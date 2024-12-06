window.addEventListener("load", () => {
  const checkoutContainer = document.getElementById("checkout-container-div");
  if (checkoutContainer !== null) {
    initPaymentWindow(checkoutContainer);
  }
});

function initPaymentWindow(checkoutContainer, retried = false) {
  const { checkoutKey, createPaymentUrl, paymentErrorMessage, checkoutLanguage = 'da-DK' } = checkoutContainer.dataset;
  const request = new XMLHttpRequest();
  request.open("POST", createPaymentUrl, true);
  request.onload = function () {
    const data = JSON.parse(this.response);
    if (!data.paymentId) {
        alert(paymentErrorMessage);
      return;
    }
    const paymentId = data.paymentId;
    const checkoutOptions = {
      checkoutKey: checkoutKey,
      paymentId: paymentId,
      containerId: checkoutContainer.id,
    };

    const checkout = new Dibs.Checkout(checkoutOptions);
    checkout.setLanguage(checkoutLanguage);
    checkout.on("payment-completed", function (payload) {
      const paymentIdCompleted = payload.paymentId;
      if (paymentId === paymentIdCompleted) {
        document.querySelector(
          "input[name='os2forms_payment_reference_field']"
        ).value = paymentIdCompleted;
        document.getElementById("edit-submit").click();
      } else {
        alert(paymentErrorMessage)
      }
    });
  };
  request.onerror = function () {
    if (retried) {
      alert(paymentErrorMessage);
      return;
    } else {
      initPaymentWindow(checkoutContainer, true);
    }

  };
  request.send();
}
