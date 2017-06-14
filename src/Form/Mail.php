<?php

namespace Drupal\massmail\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use PHPExcel_IOFactory as PHPExcel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides the send mail form.
 */
class Mail extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'massmail_mail_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'massmail.mail',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $form = parent::buildForm($form, $form_state);

    $site = $this->config('system.site');
    $settings = $this->config('massmail.settings');

    // Redirect to module config if not set.
    if (
      !$settings->get('aws_access') ||
      !$settings->get('aws_secret') ||
      !$settings->get('aws_region') ||
      !$settings->get('data_dir'))
    {
      drupal_set_message($this->t('You must first configure the general MassMail module settings to send email.'), 'warning');
      return new RedirectResponse('/admin/config/system/massmail');
    }

    $config = $this->config('massmail.mail');

    $form['to'] = array(
      '#default_value' => $config->get('to') ? $config->get('to') : '',
      '#description' => t('A valid email or identical token. Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('recipient@email.com'),
      '#type' => 'textfield',
      '#title' => t('To address'),
      '#required' => TRUE,
    );

    $form['from'] = array(
      '#default_value' => $config->get('from') ? $config->get('from') : $site->get('mail'),
      '#description' => t('A valid email or identical token. Also receives tests. Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('sender@email.com'),
      '#type' => 'textfield',
      '#title' => t('From address'),
      '#required' => TRUE,
    );

    $form['addresses'] = array(
      '#title' => t('Notification addresses'),
      '#markup' => '<p><em>' . t('For return email notifications. (Empty fields in this section will use the "from address" by default.)') . '</em></p>',
      '#type' => 'fieldset',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    );

    $form['addresses']['reply'] = array(
      '#default_value' => $config->get('reply') ? $config->get('reply') : '',
      '#description' => t('A valid email or identical token. Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('reply@email.com'),
      '#type' => 'textfield',
      '#title' => t('Reply address'),
      '#required' => FALSE,
    );

    $form['addresses']['bounce'] = array(
      '#default_value' => $config->get('bounce') ? $config->get('bounce') : '',
      '#description' => t('A valid email or identical token. Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('bounce@email.com'),
      '#type' => 'textfield',
      '#title' => t('Bounce address'),
      '#required' => FALSE,
    );

    $form['addresses']['suppressionlist'] = array(
      '#default_value' => $config->get('suppressionlist') ? $config->get('suppressionlist') : '',
      '#description' => t('A valid email or identical token. Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('unsubscribe@email.com'),
      '#type' => 'textfield',
      '#title' => t('Unsubscribe address'),
      '#required' => FALSE,
    );

    $form['subject'] = array(
      '#default_value' => $config->get('subject') ? $config->get('subject') : '',
      '#description' => t('Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('Regarding Your MassTimes Account'),
      '#type' => 'textfield',
      '#title' => t('Subject'),
      '#required' => TRUE,
    );

    $form['body'] = array(
      '#default_value' => $config->get('body') ? $config->get('body') : '',
      '#description' => t('Service data will be auto-added below the body intro. Tokens from "church" are available, e.g. [[column_name]]'),
      '#placeholder' => t('Greetings! This is a friendly message from MassTimes regarding your account information...'),
      '#type' => 'textarea',
      '#title' => t('Body intro'),
      '#required' => TRUE,
    );

    $form['source_fid_0'] = array(
      '#title' => t('Church data (csv)'),
      '#type' => 'managed_file',
      '#upload_location' => \Drupal::config('system.file')->get('default_scheme') . '://' . $settings->get('data_dir'),
      '#upload_validators' => array(
        'file_validate_extensions' => array('csv'),
      ),
      '#required' => TRUE,
      '#weight' => 1,
    );

    if ($fid = $config->get('source_fid_0')) {
      $file = File::load($fid);
      $form['source_fid_0']['#description'] = t('Uploaded data file:') . ' <a href="'. file_create_url($file->getFileUri()) .'">'. $file->getFileUri() .'</a>';
      $form['source_fid_0']['#required'] = FALSE;
    }

    $form['source_fid_1'] = array(
      '#title' => t('Services data (csv)'),
      '#type' => 'managed_file',
      '#upload_location' => \Drupal::config('system.file')->get('default_scheme') . '://' . $settings->get('data_dir'),
      '#upload_validators' => array(
        'file_validate_extensions' => array('csv'),
      ),
      '#required' => TRUE,
      '#weight' => 2,
    );

    if ($fid = $config->get('source_fid_1')) {
      $file = File::load($fid);
      $form['source_fid_1']['#description'] = t('Uploaded data file:') . ' <a href="'. file_create_url($file->getFileUri()) .'">'. $file->getFileUri() .'</a>';
      $form['source_fid_1']['#required'] = FALSE;
    }

    $form['actions']['#type'] = 'actions';

    $form['actions']['save'] = array(
      '#type' => 'submit',
      '#value' => 'Save',
      '#submit' => ['::saveSubmit'],
      '#weight' => 10,
    );

    $form['actions']['send_test'] = array(
      '#attributes' => array(
        'class' => ['btn-warning'],
        'onClick' => 'alert(\'Are you sure you want to test?\'); jQuery(this).addClass(\'disabled\');',
      ),
      '#type' => 'submit',
      '#value' => 'Save & Test',
      '#submit' => ['::testSubmit'],
      '#weight' => 11,
    );

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Send'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['btn-danger'],
        'onClick' => 'alert(\'CONFIRM LIVE SENDING: Are you sure you want to do this?\'); jQuery(this).addClass(\'disabled\');',
      ],
      '#weight' => 12,
    ];

    return $form;
  }

  public function saveSubmit(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('massmail.mail');

    $config
      ->set('to', $values['to'])
      ->set('from', $values['from'])
      ->set('reply', $values['reply'])
      ->set('bounce', $values['bounce'])
      ->set('suppressionlist', $values['suppressionlist'])
      ->set('subject', $values['subject'])
      ->set('body', $values['body'])
      ->save();

    if (count($values['source_fid_0'])) {
      $fid = $values['source_fid_0'][0];
      $config
        ->set('source_fid_0', $fid)
        ->save();
    }

    if (count($values['source_fid_1'])) {
      $fid = $values['source_fid_1'][0];
      $config
        ->set('source_fid_1', $fid)
        ->save();
    }

    drupal_set_message($this->t('Settings saved.'));
  }

  /**
   * {@inheritdoc}
   */
  public function testSubmit(array &$form, FormStateInterface $form_state) {
    $this->saveSubmit($form, $form_state);

    // Delete logfile here to ensure the correct status page.
    $logpath = massmail_data_dir() . '/massmail.log';
    if (file_exists($logpath)) {
      unlink($logpath);
    }
    fopen($logpath, 'w') or die('Can not create logfile at: ' . $logpath);

    // Start a detached background process.
    $path = DRUPAL_ROOT . '/' . drupal_get_path('module', 'massmail') . '/src/MassMailProcess.php';
    exec("php $path " . DRUPAL_ROOT . " test > /dev/null 2>/dev/null &");
    drupal_set_message($this->t('Testing MassMail email queue.'));

    // Redirect to status page.
    $url = \Drupal\Core\Url::fromRoute('massmail.status');
    $form_state->setRedirectUrl($url);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->saveSubmit($form, $form_state);

    // Delete logfile here to ensure the correct status page.
    $logpath = massmail_data_dir() . '/massmail.log';
    if (file_exists($logpath)) {
      unlink($logpath);
    }
    fopen($logpath, 'w') or die('Can not create logfile at: ' . $logpath);

    // Start a detached background process.
    $path = DRUPAL_ROOT . '/' . drupal_get_path('module', 'massmail') . '/src/MassMailProcess.php';
    exec("php $path " . DRUPAL_ROOT . " live > /dev/null 2>/dev/null &");
    drupal_set_message($this->t('Sending MassMail email queue.'));

    // Redirect to status page.
    $url = \Drupal\Core\Url::fromRoute('massmail.status');
    $form_state->setRedirectUrl($url);
  }

}
