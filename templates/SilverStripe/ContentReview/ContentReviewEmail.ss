<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
  <head></head>
  <body style="font-family: Arial, sans-serif;">
    <table id="Content" cellspacing="1" cellpadding="4">
      <tbody>
        <tr>
          <td scope="row" colspan="2" class="typography">
            $EmailBody
          </td>
        </tr>
        <% loop $Pages %>
		<% if $Pos == 1 %>
		<tr><td colspan="3"><hr /></td></tr>
		<% end_if %>
        <tr>
          <td valign="top" colspan="3"><strong>$Title</strong></td>
        </tr>
        <tr>
          <td valign="top" colspan="3"><strong><a href="$AbsoluteLink">$AbsoluteLink</a></strong></td>
        </tr>
        <tr>
          <td colspan="3">
            <a href="{$BaseURL}admin/pages/edit/show/$ID">Review in the CMS</a><br />
          </td>
        </tr>
        <tr>
          <td colspan="3">
			<% if $isReviewDueByNextReviewDate %>
				<p>Review is due because the Next Review Date has passed.</p>
			<% else_if $isReviewDueByLastEditedAge %>
				<p>Review is due because this page was last edited more than $SiteConfig.ReviewPeriodDays days ago.</p>
			<% end_if %>
          </td>
        </tr>
		<area>
			<td colspan="3">
				<table cellpadding="2" >
					<tr>
						<td valign="top">Owner(s):</td>
						<td>$OwnerNames</td>
					</tr>
					<tr>
						<td valign="top">Last Edited:</td>
						<td>$LastEdited.Nice</td>
					</tr>
					<tr>
						<td valign="top">Last Reviewed:</td>
						<td>$LastReviewed.Nice</td>
					</tr>
					<tr>
						<td valign="top">Next Review Date:</td>
						<td>$NextReviewDate.Nice</td>
					</tr>
				</table>
				<table cellpadding="2">
					<tr>
						<td colspan="2">
							Responsible SMEs:<br />
							$ResponsibleSMEs
						</td>
					</tr>
				</table>
			</td>
		</tr>
        <tr><td colspan="3"><hr /></td></tr>
        <% end_loop %>
      </tbody>
    </table>
  </body>
</html>
