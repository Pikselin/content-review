<?php

namespace SilverStripe\ContentReview\Tasks;

use Page;
use RuntimeException;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\SS_List;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ArrayData;
use SilverStripe\Security\Member;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ContentReview\Compatibility\ContentReviewCompatability;

/**
 * Daily task to send emails to the owners of content items when the review date rolls around.
 */
class ContentReviewEmails extends BuildTask
{
    protected $title = 'Content Review: Send emails';

    protected $description = 'Daily task to send emails to the owners of content items when the review date rolls around.';

    private array $invalid_emails = [];

    /**
     * @param HTTPRequest $request
     * @throws RuntimeException
     */
    public function run($request)
    {
        if (!$this->isValidEmail($senderEmail = SiteConfig::current_site_config()->ReviewFrom)) {
            throw new RuntimeException(
                sprintf(
                    'Provided sender email address is invalid: "%s".',
                    $senderEmail
                )
            );
        }
        echo 'Emails sent from: ' . $senderEmail . '<br />';

        $compatibility = ContentReviewCompatability::start();

        // Get now and review threshold
        $now = DBDatetime::now();
        $config = SiteConfig::current_site_config();
        $reviewThreshold = DBDatetime::now()->modify('-' . $config->ReviewPeriodDays . ' days');

        // First set: pages with custom NextReviewDate due
        $pagesWithCustomReview = Page::get()
            ->filter('NextReviewDate:LessThanOrEqual', $now->Format(DBDatetime::ISO_DATETIME));

        // Second set: pages using inherited review type and stale enough
        $pagesWithInheritedReview = Page::get()
            ->filter([
                'ContentReviewType' => 'Inherit',
                'NextReviewDate' => null,
                'LastEdited:LessThanOrEqual' => $reviewThreshold->Format(DBDatetime::ISO_DATETIME),
            ]);

        // Merge results, avoiding duplicates
        $mergedPages = new ArrayList();
        $seenIDs = [];

        foreach ($pagesWithCustomReview as $page) {
            $mergedPages->push($page);
            $seenIDs[$page->ID] = true;
        }

        foreach ($pagesWithInheritedReview as $page) {
            if (!isset($seenIDs[$page->ID])) {
                if ($page->ReviewLogs()->filter(['LastEdited:LessThanOrEqual' => $reviewThreshold->Format(DBDatetime::ISO_DATETIME)])->count() === 0) {
                    $mergedPages->push($page);
                }
            }
        }

        // Debug::dump($mergedPages->count());

        $overduePages = $this->getOverduePagesForOwners($mergedPages);

        // Lets send one email to one owner with all the pages in there instead of no of pages
        // of emails.
        foreach ($overduePages as $memberID => $mergedPages) {
            $this->notifyOwner($memberID, $mergedPages);
        }

        ContentReviewCompatability::done($compatibility);

        if (is_array($this->invalid_emails) && count($this->invalid_emails) > 0) {
            $plural = count($this->invalid_emails) > 1 ? 's are' : ' is';
            throw new RuntimeException(
                sprintf(
                    'Provided email' . $plural . ' invalid: "%s".',
                    implode(', ', $this->invalid_emails)
                )
            );
        }
    }

    /**
     * @param SS_List $pages
     *
     * @return array
     */
    protected function getOverduePagesForOwners(SS_List $pages)
    {
        $overduePages = [];

        foreach ($pages as $page) {
            if (!$page->canBeReviewedBy()) {
                continue;
            }

            // get most recent review log of current [age]
            $contentReviewLog = $page->ReviewLogs()->sort('Created DESC')->first();

            // check log date vs NextReviewDate. If someone has left a content review
            // after the review date, then we don't need to notify anybody
            if ($contentReviewLog && $contentReviewLog->Created >= $page->NextReviewDate) {
                $page->advanceReviewDate();
                continue;
            }

            $options = $page->getOptions();

            if ($options) {
                foreach ($options->ContentReviewOwners() as $owner) {
                    if (!isset($overduePages[$owner->ID])) {
                        $overduePages[$owner->ID] = ArrayList::create();
                    }

                    $overduePages[$owner->ID]->push($page);
                }
            }
        }

        return $overduePages;
    }

    /**
     * @param int           $ownerID
     * @param array|SS_List $pages
     */
    protected function notifyOwner($ownerID, SS_List $pages)
    {
        // Prepare variables
        $siteConfig = SiteConfig::current_site_config();
        $owner = Member::get()->byID($ownerID);

        if (!$this->isValidEmail($owner->Email)) {
            $this->invalid_emails[] = $owner->Name . ': ' . $owner->Email;

            return;
        }

        $templateVariables = $this->getTemplateVariables($owner, $siteConfig, $pages);

        // Build email
        $email = Email::create();
        $email->setTo($owner->Email);
        $email->setFrom($siteConfig->ReviewFrom);
        $email->setSubject($siteConfig->ReviewSubject);

        // Get user-editable body
        $body = $this->getEmailBody($siteConfig, $templateVariables);

        // Debug::dump($body);
        echo sprintf('%d %s that need to be reviewed, sent to: %s<br />',
            $pages->Count(),
            $pages->Count() === 1 ? 'page' : 'pages',
            $owner->Email
        );

        // Populate mail body with fixed template
        $email->setHTMLTemplate($siteConfig->config()->get('content_review_template'));
        $email->setData(
            array_merge(
                $templateVariables,
                [
                    'EmailBody' => $body,
                    'Recipient' => $owner,
                    'Pages' => $pages,
                ]
            )
        );
        $email->send();

        // Debug::dump('email sent');
    }

    /**
     * Get string value of HTML body with all variable evaluated.
     *
     * @param SiteConfig $config
     * @param array List of safe template variables to expose to this template
     *
     * @return HTMLText
     */
    protected function getEmailBody($config, $variables)
    {
        $template = SSViewer::fromString($config->ReviewBody);
        $value = $template->process(ArrayData::create($variables));

        // Cast to HTML
        return DBField::create_field('HTMLText', (string) $value);
    }

    /**
     * Gets list of safe template variables and their values which can be used
     * in both the static and editable templates.
     *
     * {@see ContentReviewAdminHelp.ss}
     *
     * @param Member     $recipient
     * @param SiteConfig $config
     * @param SS_List    $pages
     *
     * @return array
     */
    protected function getTemplateVariables($recipient, $config, $pages)
    {
        return [
            'Subject' => $config->ReviewSubject,
            'PagesCount' => $pages->count(),
            'FromEmail' => $config->ReviewFrom,
            'ToFirstName' => $recipient->FirstName,
            'ToSurname' => $recipient->Surname,
            'ToEmail' => $recipient->Email,
        ];
    }

    /**
     * Check validity of email
     */
    protected function isValidEmail(?string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
