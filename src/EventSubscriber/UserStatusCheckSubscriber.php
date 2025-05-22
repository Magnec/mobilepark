<?php

namespace Drupal\mobilpark_sms_gateway\EventSubscriber;

use Drupal\Core\Database\Database;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Session\AccountProxyInterface;

class UserStatusCheckSubscriber implements EventSubscriberInterface {
  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * RequestSubscriber constructor.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(RouteMatchInterface $route_match, RequestStack $request_stack = NULL, AccountProxyInterface $current_user = NULL) {
    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack ?: \Drupal::service('request_stack');
    $this->currentUser = $current_user ?: \Drupal::currentUser();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequest', 28], // Yüksek öncelikle çalıştır (varsayılan 0)
    ];
  }

  public function onRequest() {
    $user_id = $this->currentUser->id();
    
    // Eğer kullanıcı anonim ise işlem yapma
    if ($user_id == 0) {
      return;
    }
    
    $user = User::load($user_id);
    
    // Admin kullanıcıları doğrulama sürecinden muaf tut
    if ($user->hasPermission('administer users')) {
      return;
    }
    
    // Telefon numarası alanını kontrol et
    if (!isset($user->phone_number) || empty($user->phone_number->value)) {
      return;
    }
    
    $phone_number = $user->phone_number->value;
    
    $currentRoute = $this->routeMatch->getRouteName();
    
    // İzin verilen rotalar listesi (doğrulama sayfası, çıkış sayfası, profil sayfası)
    $allowed_routes = [
      'mobilpark_sms_gateway.verify_otp_form',
      'user.logout',
      'user.logout.confirm',
      'entity.user.edit_form',
      'user.page', // Kullanıcı profil sayfası
      'system.404',
      'system.403',
    ];
    
    // İzin verilen rotalardaysa kontrolü atla
    if (in_array($currentRoute, $allowed_routes)) {
      return;
    }
    
    $connection = Database::getConnection();
    
    // Veritabanında tablo var mı kontrol et
    try {
      $schema = $connection->schema();
      if (!$schema->tableExists('sms_phone_number_verification')) {
        \Drupal::logger('mobilpark_sms_gateway')->error('sms_phone_number_verification tablosu bulunamadı.');
        return;
      }
    
      $query = $connection->select('sms_phone_number_verification', 's')
        ->fields('s', ['status'])
        ->condition('phone', $phone_number, '=')
        ->orderBy('created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
    
      // Eğer doğrulama kaydı yoksa veya status = 0 ise doğrulama sayfasına yönlendir
      if (!$query) {
        // Yeni bir doğrulama kaydı oluştur ve doğrulanmamış olarak işaretle
        $code = mt_rand(100000, 999999);
        
        $connection->insert('sms_phone_number_verification')
          ->fields([
            'phone' => $phone_number,
            'code' => $code,
            'status' => 0, // Doğrulanmamış olarak işaretle
            'created' => \Drupal::time()->getRequestTime(),
          ])
          ->execute();
        
        // SMS mesajı oluştur ve gönder - string'e dönüştür
        $message = t('Doğrulama kodunuz: @code', ['@code' => $code])->render();
        mobilpark_sms_gateway_send_sms($phone_number, $message);
        
        $response = new RedirectResponse('/tr/verify-otp');
        $response->send();
        return;
      } else if ($query && $query['status'] == '0') {
        $response = new RedirectResponse('/tr/verify-otp');
        $response->send();
        return;
      }
    } catch (\Exception $e) {
      \Drupal::logger('mobilpark_sms_gateway')->error('Telefon doğrulama durumu kontrol edilirken hata: @error', ['@error' => $e->getMessage()]);
    }
  }
}