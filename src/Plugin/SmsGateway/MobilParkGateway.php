<?php

namespace Drupal\mobilpark_sms_gateway\Plugin\SmsGateway;

use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms\Message\SmsMessageResultStatus;
use Drupal\sms\Message\SmsDeliveryReport;

/**
 * Provides MobilPark SMS gateway.
 *
 * @SmsGateway(
 *   id = "mobilpark_gateway",
 *   label = @Translation("MobilPark SMS Gateway"),
 *   outgoing_message_max_recipients = -1,
 * )
 */
class MobilParkGateway extends SmsGatewayPluginBase {

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message) {
    \Drupal::logger('mobilpark_sms_gateway')->notice('MobilPark Gateway send() çağrıldı');
    
    // API bilgilerini al
    $api_url = 'http://otpservice.mobilpark.biz/http/SendMsg.aspx';
    $username = $this->configuration['username']; 
    $password = $this->configuration['password'];
    $from = $this->configuration['from'];

    // SMS bilgilerini al
    $recipients = $sms_message->getRecipients();
    $message = $sms_message->getMessage();

    // Sonuç objesini oluştur
    $result = new SmsMessageResult();
    
    // Hiç alıcı yoksa hata döndür
    if (empty($recipients)) {
      return $result->setError(SmsMessageResultStatus::ERROR, 'No recipients specified');
    }
    
    try {
      // HTTP client oluştur
      $client = new Client();
      
      // Her alıcı için API'ye istek gönder ve rapor oluştur
      foreach ($recipients as $recipient) {
        // MobilPark API'ye gönderilecek veri
        $data = [
          'username' => $username,
          'password' => $password,
          'to' => $recipient,
          'messageType'=> 'sms',
          'text' => $message,
          'from' => $from,
        ];
        
        // API'ye istek gönder
        $response = $client->post($api_url, [
          'form_params' => $data,
        ]);
        
        // Yanıtı al
        $response_body = $response->getBody()->getContents();
        \Drupal::logger('mobilpark_sms_gateway')->notice('API yanıtı: @response', ['@response' => $response_body]);
        
        // Teslim raporu oluştur
        $report = new SmsDeliveryReport();
        $report->setRecipient($recipient);
        
        // Yanıtı kontrol et ve durumu ayarla - doğru status sabiti kullan
        if ($response->getStatusCode() == 200) {
          // "delivered" status kullan (sabit değil, string)
          $report->setStatus('delivered');
          // Benzersiz bir mesaj ID'si oluştur
          $report->setMessageId(uniqid('mobilpark_', true));
        } else {
          // "failed" status kullan (sabit değil, string)
          $report->setStatus('failed');
        }
        
        // Raporu sonuca ekle
        $result->addReport($report);
      }
      
      return $result;
    } catch (RequestException $e) {
      \Drupal::logger('mobilpark_sms_gateway')->error('SMS gönderiminde hata: @error', ['@error' => $e->getMessage()]);
      
      // Hata durumunda tüm alıcılar için hata raporları oluştur
      foreach ($recipients as $recipient) {
        $report = new SmsDeliveryReport();
        $report->setRecipient($recipient);
        $report->setStatus('failed'); // String olarak status
        $result->addReport($report);
      }
      
      return $result->setError(SmsMessageResultStatus::ERROR, $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'username' => '',
      'password' => '',
      'from' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['username'],
      '#required' => TRUE,
    ];
    $form['password'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Password'),
        '#default_value' => $this->configuration['password'],
        '#required' => TRUE,
      ];
    $form['from'] = [
      '#type' => 'textfield',
      '#title' => $this->t('from'),
      '#default_value' => $this->configuration['from'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['username'] = $form_state->getValue('username');
    $this->configuration['password'] = $form_state->getValue('password');
    $this->configuration['from'] = $form_state->getValue('from');
  }
}