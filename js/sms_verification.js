(function ($, Drupal) {
  Drupal.behaviors.smsVerification = {
    attach: function (context) {
      if ($("#send_otp").length) {
        const isTestParam = new URLSearchParams(window.location.search).get(
          "test"
        );

        var countdownTimer;
        var secondsRemaining = isTestParam ? 5 : 60;

        $("#send_otp").removeClass("d-none");
        $("#send_otp", context).prop("disabled", true);

        function startCountdown() {
          countdownTimer = setInterval(function () {
            secondsRemaining--;
            console.log("Kalan saniye: " + secondsRemaining);

            $("#send_otp", context)
              .val("Tekrar Gönder (" + secondsRemaining + " sn)")
              .prop("disabled", true);

            if (secondsRemaining <= 0) {
              clearInterval(countdownTimer);
              // remove the disabled attribute
              $("#send_otp", context)
                .val("Tekrar Gönder")
                .removeAttr("disabled");
            }
          }, 1000);
        }

        startCountdown();
      }
    },
  };
})(jQuery, Drupal);