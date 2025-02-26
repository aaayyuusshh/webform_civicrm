<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\Core\Test\AssertMailTrait;

/**
 * Tests submitting a Webform with CiviCRM: existing contact element.
 *
 * @group webform_civicrm
 */
final class ExistingContactElementTest extends WebformCivicrmTestBase {

  use AssertMailTrait;

  private function addcontactinfo() {
    $currentUserUF = $this->getUFMatchRecord($this->rootUser->id());
    $params = [
      'contact_id' => $currentUserUF['contact_id'],
      'first_name' => 'Maarten',
      'last_name' => 'van der Weijden',
    ];
    $utils = \Drupal::service('webform_civicrm.utils');
    $result = $utils->wf_civicrm_api('Contact', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
  }

  public function testSubmitWebform() {

    $this->addcontactinfo();

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();
    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();

    $this->assertSession()->waitForField('First Name');

    // The Default Existing Contact Element behaviour is: load logged in User
    // The test here is to check if the fields on the form populate with Contact details belonging to the logged in User:
    $this->assertSession()->fieldValueEquals('First Name', 'Maarten');
    $this->assertSession()->fieldValueEquals('Last Name', 'van der Weijden');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
  }


  /**
   * Verify if existing contact element is loaded as expected.
   */
  function testRenderingOfExistingContactElement() {
    $this->addcontactinfo();
    $childContact = [
      'first_name' => 'Fred',
      'last_name' => 'Pinto',
    ];
    $this->childContact = $this->createIndividual($childContact);
    $this->utils->wf_civicrm_api('Relationship', 'create', [
      'contact_id_a' => $this->childContact['id'],
      'contact_id_b' => $this->rootUserCid,
      'relationship_type_id' => "Child of",
    ]);

    $this->drupalLogin($this->rootUser);

    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    // The label has a <div> in it which can cause weird failures here.
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->getSession()->getPage()->selectFieldOption("number_of_contacts", 4);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    foreach ([2, 3, 4] as $c) {
      $this->getSession()->getPage()->clickLink("Contact {$c}");
      $this->assertSession()->assertWaitOnAjaxRequest();
      //Make second contact as household contact.
      if ($c == 2) {
        $this->getSession()->getPage()->selectFieldOption("{$c}_contact_type", 'Household');
        $this->assertSession()->assertWaitOnAjaxRequest();
      }
      elseif ($c == 3) {
        $this->getSession()->getPage()->checkField("edit-civicrm-{$c}-contact-1-contact-job-title");
        $this->assertSession()->checkboxChecked("edit-civicrm-{$c}-contact-1-contact-job-title");
      }
      $this->getSession()->getPage()->checkField("civicrm_{$c}_contact_1_contact_existing");
      $this->assertSession()->checkboxChecked("civicrm_{$c}_contact_1_contact_existing");
    }

    $this->saveCiviCRMSettings();

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Edit contact element 1.
    $editContact = [
      'title' => 'Primary Contact',
      'selector' => 'edit-webform-ui-elements-civicrm-1-contact-1-contact-existing-operations',
      'widget' => 'Static',
      'description' => 'Description of the static contact element.',
      'hide_fields' => 'Email',
    ];
    $this->editContactElement($editContact);

    // Edit contact element 2.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-2-contact-1-contact-existing-operations',
      'widget' => 'Static',
    ];
    $this->editContactElement($editContact, FALSE);

    // Edit contact element 3.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-3-contact-1-contact-existing-operations',
      'widget' => 'Autocomplete',
    ];
    $this->editContactElement($editContact, FALSE);

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Set a default value for Job title.
    $this->assertSession()->elementExists('css', "[data-drupal-selector='edit-webform-ui-elements-civicrm-3-contact-1-contact-job-title-operations'] a.webform-ajax-link")->click();
    $this->assertSession()->waitForElementVisible('xpath', '//a[contains(@id, "--advanced")]');
    $this->assertSession()->elementExists('xpath', '//a[contains(@id, "--advanced")]')->click();
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-default"]')->click();

    $this->getSession()->getPage()->fillField('properties[default_value]', 'Accountant');
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('Job Title has been updated');

    $this->drupalGet($this->webform->toUrl('edit-form'));
    // Edit contact element 4.
    $editContact = [
      'selector' => 'edit-webform-ui-elements-civicrm-4-contact-1-contact-existing-operations',
      'widget' => 'Static',
      'default' => 'relationship',
      'default_relationship' => [
        'default_relationship_to' => 'Contact 3',
        'default_relationship' => 'Child of Contact 3',
      ],
    ];
    $this->editContactElement($editContact, FALSE);

    // Visit the webform.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->htmlOutput();

    // Check if static title is displayed.
    $this->assertSession()->pageTextContains('Primary Contact');
    $this->assertSession()->pageTextContains('Description of the static contact element');
    //Make sure email field is not loaded.
    $this->assertFalse($this->getSession()->getDriver()->isVisible($this->cssSelectToXpath('.form-type-email')));

    // Check if "None Found" text is present in the static element.
    $this->assertSession()->elementTextContains('css', '[id="edit-civicrm-2-contact-1-fieldset-fieldset"]', '- None Found -');

    // Check if c4 contains the text for "create new".
    $this->assertSession()->elementTextContains('css', '[id="edit-civicrm-4-contact-1-fieldset-fieldset"]', '+ Create new +');

    // Enter contact 3.
    $this->fillContactAutocomplete('token-input-edit-civicrm-3-contact-1-contact-existing', 'Maarten');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertFieldValue('edit-civicrm-3-contact-1-contact-job-title', 'Accountant');

    // Check if related contact is loaded on c4.
    $this->htmlOutput();
    $this->assertSession()->elementTextContains('css', '[id="edit-civicrm-4-contact-1-fieldset-fieldset"]', 'Fred Pinto');
  }

  /**
   * Test Tokens in Email.
   */
  public function testTokensInEmail() {
    //Create 2 meeting activities for the contact.
    $actID1 = $this->utils->wf_civicrm_api('Activity', 'create', [
      'source_contact_id' => $this->rootUserCid,
      'activity_type_id' => "Meeting",
      'target_id' => $this->rootUserCid,
    ])['id'];
    $actID2 = $this->utils->wf_civicrm_api('Activity', 'create', [
      'source_contact_id' => $this->rootUserCid,
      'activity_type_id' => "Meeting",
      'target_id' => $this->rootUserCid,
    ])['id'];

    $this->drupalLogin($this->rootUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();

    // Enable Email address
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->checkboxChecked("civicrm_1_contact_1_email_email");
    $this->getSession()->getPage()->selectFieldOption('civicrm_1_contact_1_email_location_type_id', 'Main');

    $this->getSession()->getPage()->clickLink('Activities');
    $this->getSession()->getPage()->selectFieldOption('activity_number_of_activity', 2);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->htmlOutput();

    $this->saveCiviCRMSettings();

    $email = [
      'to_mail' => '[webform_submission:values:civicrm_1_contact_1_email_email:raw]',
      'body' => 'Existing Contact - [webform_submission:values:civicrm_1_contact_1_contact_existing]. Activity 1 ID - [webform_submission:activity-id:1]. Activity 2 ID - [webform_submission:activity-id:2]. Webform CiviCRM Contacts IDs - [webform_submission:contact-id:1]. Webform CiviCRM Contacts Links - [webform_submission:contact-link:1].',
    ];
    $this->addEmailHandler($email);
    $this->drupalGet($this->webform->toUrl('handlers'));
    $civicrm_handler = $this->assertSession()->elementExists('css', '[data-webform-key="webform_civicrm"] a.tabledrag-handle');
    // Move up to be the top-most handler.
    $this->sendKeyPress($civicrm_handler, 38);
    $this->getSession()->getPage()->pressButton('Save handlers');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->drupalGet($this->webform->toUrl('canonical', ['query' => ['activity1' => $actID1, 'activity2' => $actID2]]));
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Last Name', 'Pabst');
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');

    $this->getSession()->getPage()->pressButton('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');
    $sent_email = $this->getMails();

    $cidURL = Url::fromUri('internal:/civicrm/contact/view', [
      'absolute' => TRUE,
      'query' => ['reset' => 1, 'cid' => $this->rootUserCid]
    ])->toString();
    // Check if email was sent to contact 1.
    $this->assertStringContainsString('frederick@pabst.io', $sent_email[0]['to']);

    // Verify tokens are rendered correctly.
    $this->assertStringContainsString('Existing Contact - Frederick Pabst.', $sent_email[0]['body']);
    $this->assertStringContainsString("Activity 1 ID - {$actID1}", $sent_email[0]['body']);
    $this->assertStringContainsString("Activity 2 ID - {$actID2}", $sent_email[0]['body']);
    $this->assertStringContainsString("Webform CiviCRM Contacts IDs - {$this->rootUserCid}", $sent_email[0]['body']);
    $this->assertStringContainsString($cidURL, $sent_email[0]['body']);
  }

}
