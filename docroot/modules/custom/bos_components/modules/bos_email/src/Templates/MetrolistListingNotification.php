<?php

namespace Drupal\bos_email\Templates;

use Drupal\bos_email\CobEmail;
use Drupal\bos_email\Controller\PostmarkAPI;
use Drupal\bos_email\EmailTemplateBase;
use Drupal\bos_email\EmailTemplateInterface;

/**
 * Template class for Postmark API.
 */
class MetrolistListingNotification extends EmailTemplateBase implements EmailTemplateInterface {

  /**
   * @inheritDoc
   */
  public static function templatePlainText(&$emailFields):void {

    $cobdata = &$emailFields["postmark_data"];

    $vars = self::_getRequestParams();
    $decisions = "";
    $new = "";
    if ($vars["new"]) {
      $new = "new ";
      $decisions = "Updates: " . implode("\n         ", $vars["decisions"]);
    }
    $text = "
A {$new}Metrolist Listing form submission has been completed on boston.gov:\n
Submitted on: {$vars["completed"]}
Submitted By: {$vars["contact_name"]}
Listing Contact Company: {$vars["contact_company"]}
Contact Email: {$vars["contact_email"]}
Contact Phone: {$vars["contact_phone"]}
Property Name: {$vars["property_name"]}
Property Address: {$vars["street_address"]}, {$vars["city"]}, {$vars["zip_code"]}
{$decisions}
View Development: https://boston-dnd.lightning.force.com/lightning/r/Development__c/{$vars["developmentsfid"]}/view{$vars["developmentsfid"]}\n\n
--------------------------------
View Pending Development Units: https://boston-dnd.lightning.force.com/lightning/o/Development_Unit__c/list?filterName=00B0y00000A4vQ3EAJ
This submission was made via the Metrolist Listing form on Boston.gov (" . urldecode($emailFields['url']) . ")
--------------------------------
";

    $cobdata->setField("TextBody", $text);

  }

  /**
   * @inheritDoc
   */
  public static function templateHtmlText(&$emailFields):void {

    $cobdata = &$emailFields["postmark_data"];

    $vars = self::_getRequestParams();

    $weblink = "";
    if (!empty($vars["website_link"])) {
      $weblink = "<p class='txt'><a href='{$vars["website_link"]}'>Property Link</a></p>";
    }

    $contact = "{$vars["contact_name"]}";
    if (!empty($vars["contactsfid"])) {
      $contact = "<a href='https://boston-dnd.lightning.force.com/lightning/r/Contact/{$vars["contactsfid"]}/view'>{$vars["contact_name"]}</a>";
    }

    $development = $vars["property_name"];
    if (!empty($vars["developmentsfid"])) {
      $development = "<a href='https://boston-dnd.lightning.force.com/lightning/r/Development__c/{$vars["developmentsfid"]}/view'>{$vars["property_name"]}</a>";
    }

    $head = "A Metrolist Property has been updated using the Metrolist Listing form on boston.gov";
    if ($vars["new"]) {
      $head = "A new Metrolist Listing form submission has been completed on boston.gov";
    }

    $decisions = "";
    if (!empty($vars["decisions"])) {
      $decisions = "<tr><td><span class='txt-b'>Updates:</span></td><td>" . implode("<br>", $vars["decisions"]);"</td></tr>";
    }

    $html = "
<img class='ml-icon' height='34' src='https://assets.boston.gov/icons/metrolist/metrolist-logo_email.png' />\n
<p class='txt'>{$head}</p>\n
<p class='txt'><table class='moh-signature' cellpadding='0' cellspacing='0' border='0'>\n
<tr><td><span class='txt-b'>Submitted on:</span></td><td>{$vars["completed"]}</td></tr>\n
<tr><td><span class='txt-b'>Submitted By:</span></td><td>{$contact}</td></tr>\n
<tr><td><span class='txt-b'>Listing Contact Company:</span></td><td>{$vars["contact_company"]}</td></tr>\n
<tr><td><span class='txt-b'>Contact Email:</span></td><td>{$vars["contact_email"]}</td></tr>\n
<tr><td><span class='txt-b'>Contact Phone:</span></td><td>{$vars["contact_phone"]}</td></tr>\n
<tr><td><span class='txt-b'>Property Name:</span></td><td>{$development}</td></tr>\n
<tr><td><span class='txt-b'>Property Address:</span></td><td>{$vars["street_address"]}, {$vars["city"]}, {$vars["zip_code"]}</td></tr>\n
{$decisions}\n
</table></p>\n
{$weblink}\n
<hr>
<p class='txt'><a href='https://boston-dnd.lightning.force.com/lightning/o/Development_Unit__c/list?filterName=00B0y00000A4vQ3EAJ'>View Pending Development Units</a></p>\n
<p class='txt'>This submission was made via the <a href='{$emailFields['url']}'>Metrolist Listing Form</a> on Boston.gov.</p>\n\n
<hr>\n
";

    $emailFields["HtmlBody"] = self::_makeHtml($html, $emailFields["subject"]);

    $cobdata->setField("HtmlBody", $html);

  }

  /**
   * @inheritDoc
   */
  public static function formatOutboundEmail(array &$emailFields): void {

    $cobdata = &$emailFields["postmark_data"];

    $cobdata->setField("Tag", "metrolist notification");

    $cobdata->setField("endpoint", $emailFields["endpoint"] ?: PostmarkAPI::POSTMARK_DEFAULT_ENDPOINT);

    self::templatePlainText($emailFields);
    if (!empty($emailFields["useHtml"])) {
      self::templateHtmlText($emailFields);
    }

    // Create a hash of the original poster's email
    $cobdata->setField("To", $emailFields["to_address"]);
    $cobdata->setField("From", $emailFields["from_address"]);
    !empty($emailFields['cc']) && $cobdata->setField("Cc", $emailFields['cc']);
    !empty($emailFields['bcc']) && $cobdata->setField("Bcc", $emailFields['bcc']);
    $cobdata->setField("Subject", $emailFields["subject"]);
    !empty($emailFields['headers']) && $cobdata->setField("Headers", $emailFields['headers']);

    // Remove redundant fields
    $cobdata->delField("TemplateModel");
    $cobdata->delField("TemplateID");

  }

  /**
   * Fetch the default email css (can extend it here if needed.
   *
   * @return string
   */
  public static function getCss(): string {
    $css = parent::getCss();
    return "<style>
        {$css}
        .ml-icon {
          height: 34px;
        }
        table.moh-signature {
          border: none;
          border-collapse: collapse;
        }
        table.moh-signature tr,
        table.moh-signature td {
          padding: 3px;
          border-collapse: collapse;
        }
      </style>";
  }

  /**
   * Helper function to extract and process request parameters.
   *
   * @return array
   */
  private static function _getRequestParams():array {
    $request = \Drupal::request();
    $output = [
      "sid" => $request->get("sid",""),
      "serial" => $request->get("serial",""),
      "new" => ($request->get("select_development", "") == "new"),
      "property_name" => $request->get("property_name",""),
      "completed" => gmdate("Y-m-d H:i", $request->get("completed", strtotime("now"))),
      "new_contact" => ($request->get("select_contact", "") == "new"),
      "contact_name" => $request->get("contact_name",""),
      "contact_company" => $request->get("contact_company",""),
      "contact_email" => $request->get("contact_email",""),
      "contact_phone" => $request->get("contact_phone",""),
      "street_address" => $request->get("street_address",""),
      "city" => $request->get("city",NULL) ?: $request->get("neighborhood","Boston"),
      "zip_code" => $request->get("zip_code",""),
      "website_link" => $request->get("website_link",""),
      "developmentsfid" => $request->get("developmentsfid",""),
      "contactsfid" => $request->get("contactsfid",""),
      "decisions" => [],
    ];

    if (!$output["new"]) {

      if (!empty( $request->get("update_building_information"))) {
        $output["decisions"][] = "- Building information";
      }
      if (!empty( $request->get("update_public_listing_information"))) {
        $output["decisions"][] = "- Public-listing information";
      }
      if (!empty( $request->get("update_unit_information")) && !empty( $request->get("add_additional_units"))) {
        $output["decisions"][] = "- Unit information";
      }
    }

    if (!$output["new_contact"] && !empty( $request->get("update_my_contact_information"))) {
        $output["decisions"][] = "- Contact information";
    }

    return $output;
  }

  /**
   * Ensures an html string is top and tailed as a full html message.
   *
   * @param string $html Html message to format
   * @param string $title Title (in head) for the html object
   *
   * @return string
   */
  public static function _makeHtml(string $html, string $title): string {
    // check for complete-ness of html
    if (stripos($html, "<html") === FALSE) {
      $output = "<html>\n<head></head>\n<body>{$html}</body>\n</html>";
    }

    // Add a title
    $output = preg_replace(
      "/\<\/head\>/i",
      "<title>{$title}</title>\n</head>",
      $output);
    // Add in the css
    $css = self::getCss();
    $output = preg_replace(
      "/\<\/head\>/i",
      "{$css}\n</head>",
      $output);

    return $output;

  }

  /**
   * @inheritDoc
   */
  public static function getHoneypotField(): string {
    // TODO: Implement honeypot() method.
    return "";
  }

  /**
   * @inheritDoc
   */
  public static function getServerID(): string {
    return "metrolist";
  }

  /**
   * @inheritDoc
   */
  public static function formatInboundEmail(array &$emailFields): void {
    // TODO: Implement incoming() method.
  }

}
