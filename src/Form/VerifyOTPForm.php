<?php

namespace Drupal\mobilpark_sms_gateway\Form;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides an OTP verification form.
 */
class VerifyOTPForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mobilpark_sms_gateway_verify_otp_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $user_id = \Drupal::currentUser()->id();
    $user = User::load($user_id);
    
    if (!$user) {
      $this->messenger()->addError($this->t('Kullanıcı bulunamadı.'));
      return [];
    }
    
    $phone_number = $user->phone_number->value;
    
    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="otp-info">' . $this->t('Telefon numaranıza bir doğrulama kodu gönderdik. Lütfen doğrulama kodunu girin.') . '</div>',
    ];
    
    $form['phone_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="phone-number">' . $this->t('Telefon numaranız: @phone', ['@phone' => $phone_number]) . '</div>',
    ];
    
    $form['otp_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Doğrulama Kodu'),
      '#required' => TRUE,
    ];

    $form['verify_otp'] = [
      '#type' => 'submit',
      '#value' => $this->t('Doğrula'),
      "#id" => "verify_otp"
    ];
    
    $form['send_otp'] = [
      '#type' => 'submit',
      '#value' => $this->t('Tekrar Gönder'),
      "#id" => "send_otp",
      '#attributes' => [
        'class' => ['d-none'],
      ],
    ];

    // JS dosyasını çağırıyoruz
    $form['#attached']['library'][] = 'mobilpark_sms_gateway/sms_verification';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user_id = \Drupal::currentUser()->id();
    $user = User::load($user_id);

    if (!$user) {
      $this->messenger()->addError($this->t('Kullanıcı bulunamadı.'));
      return;
    }

    // Telefon numarasını al
    $phone_number = $user->phone_number->value;
    
    if (empty($phone_number)) {
      $this->messenger()->addError($this->t('Telefon numarası bulunamadı.'));
      return;
    }

    // Doğrula butonuna basıldıysa
    if ($form_state->getTriggeringElement()['#id'] == 'verify_otp') {
      // Veritabanından ilgili telefon numarasına ait OTP kodunu al
      $connection = Database::getConnection();
      $query = $connection->select('sms_phone_number_verification', 's')
        ->fields('s', ['code'])
        ->condition('phone', $phone_number, '=')
        ->orderBy('created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
     
      if (!$query || empty($query['code'])) {
        $this->messenger()->addError($this->t('Doğrulama kodu bulunamadı.'));
        return;
      }
     
      $stored_otp = trim($query['code']);
      $entered_otp = trim($form_state->getValue('otp_code'));
     
      if ($entered_otp === $stored_otp) {
        // Doğrulama başarılı → Status alanını 1 yap
        $update = $connection->update('sms_phone_number_verification')
          ->fields(['status' => 1])
          ->condition('phone', $phone_number, '=')
          ->condition('code', $stored_otp, '=')
          ->execute();
     
        if ($update) {
          \Drupal::logger('sms_verification')->notice('Telefon numarası doğrulandı: @phone', ['@phone' => $phone_number]);
          $this->messenger()->addStatus($this->t('Telefon numaranız başarıyla doğrulandı.'));
          
          // Doğrulama bayrağını kaldır
          unset($_SESSION['phone_verification_required']);
          
          // Kullanıcıyı yönlendir
          $form_state->setRedirect('<front>');
        } else {
          \Drupal::logger('sms_verification')->error('Status güncellenemedi: @phone', ['@phone' => $phone_number]);
          $this->messenger()->addError($this->t('Bir hata oluştu, lütfen tekrar deneyin.'));
        }
      } else {
        $this->messenger()->addError($this->t('Girilen doğrulama kodu yanlış.'));
      }
    }
    // Tekrar Gönder butonuna basıldıysa
    else if ($form_state->getTriggeringElement()['#id'] == 'send_otp') {
      try {
        // Rastgele doğrulama kodu oluştur
        $code = mt_rand(100000, 999999);
        
        $connection = Database::getConnection();
        $verification_exists = $connection->select('sms_phone_number_verification', 's')
          ->fields('s', ['id'])
          ->condition('phone', $phone_number, '=')
          ->execute()
          ->fetchField();
        
        if ($verification_exists) {
          $connection->update('sms_phone_number_verification')
            ->fields([
              'code' => $code,
              'created' => \Drupal::time()->getRequestTime(),
            ])
            ->condition('phone', $phone_number, '=')
            ->execute();
        } else {
          $connection->insert('sms_phone_number_verification')
            ->fields([
              'phone' => $phone_number,
              'code' => $code,
              'status' => 0,
              'created' => \Drupal::time()->getRequestTime(),
            ])
            ->execute();
        }
        
        // SMS mesajı oluştur ve gönder - string'e dönüştür
        $message = t('Doğrulama kodunuz: @code', ['@code' => $code])->render();
        mobilpark_sms_gateway_send_sms($phone_number, $message);
        
        $this->messenger()->addStatus($this->t('Doğrulama kodu tekrar gönderildi.'));
      } catch (\Exception $e) {
        \Drupal::logger('mobilpark_sms_gateway')->error('Doğrulama kodu gönderilirken hata: @error', ['@error' => $e->getMessage()]);
        $this->messenger()->addError($this->t('Doğrulama kodu gönderilirken bir hata oluştu.'));
      }
    }
  }
}