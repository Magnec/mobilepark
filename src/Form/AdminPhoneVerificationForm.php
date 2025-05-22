<?php

namespace Drupal\mobilpark_sms_gateway\Form;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Admin kullanıcı telefon onaylama formu.
 */
class AdminPhoneVerificationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mobilpark_sms_gateway_admin_phone_verification_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['user_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Kullanıcı'),
      '#description' => $this->t('Kullanıcı adını girin'),
      '#target_type' => 'user',
      '#required' => TRUE,
    ];

    $form['phone_number'] = [
      '#type' => 'tel',
      '#title' => $this->t('Telefon Numarası'),
      '#description' => $this->t('Kullanıcının güncellenecek telefon numarası'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Kaydet'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user_id = $form_state->getValue('user_id');
    $phone_number = $form_state->getValue('phone_number');
    
    $user = User::load($user_id);
    if (!$user) {
      $this->messenger()->addError($this->t('Kullanıcı bulunamadı.'));
      return;
    }
    
    try {
      // Kullanıcının telefon numarasını güncelle
      if (isset($user->phone_number)) {
        // Admin güncellemesini işaretle
        $user->admin_update = TRUE;
        
        // Telefon numarasını güncelle
        $user->phone_number->value = $phone_number;
        $user->save();
        
        // Telefon numarasını doğrulanmış olarak işaretle
        _mobilpark_sms_gateway_verify_phone_number($phone_number);
        
        $this->messenger()->addStatus($this->t('Kullanıcının telefon numarası güncellendi ve onaylandı.'));
      } else {
        $this->messenger()->addError($this->t('Kullanıcıda telefon numarası alanı bulunamadı.'));
      }
      
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Bir hata oluştu: @error', ['@error' => $e->getMessage()]));
      \Drupal::logger('mobilpark_sms_gateway')->error('Telefon numarası güncellenirken hata: @error', ['@error' => $e->getMessage()]);
    }
  }
}