window.addEventListener("load", () => {
  const checkoutContainer = document.getElementById("checkout-container-div");
  if (checkoutContainer !== null) {
    initPaymentWindow(checkoutContainer);
  }
});

function initPaymentWindow(checkoutContainer) {
  const { checkoutKey, createPaymentUrl } = checkoutContainer.dataset;

  const request = new XMLHttpRequest();
  request.open("POST", createPaymentUrl, true);
  request.onload = function () {
    const data = JSON.parse(this.response);
    if (!data.paymentId) {
      console.error("Error: Check output from create-payment.php");
      return;
    }
    const paymentId = data.paymentId;
    const checkoutOptions = {
      checkoutKey: checkoutKey,
      paymentId: paymentId,
      containerId: checkoutContainer.id,
    };
    const checkout = new Dibs.Checkout(checkoutOptions);
    checkout.on("payment-completed", function (payload) {
      const paymentIdCompleted = payload.paymentId;
      if (paymentId === paymentIdCompleted) {
        document.querySelector(
          "input[name='payment_reference_field']"
        ).value = paymentIdCompleted;
        document.getElementById("edit-submit").click();
      } else {
        console.log("payment id mismatch");
      }
    });
  };
  request.onerror = function () {
    initPaymentWindow();
  };
  request.send();
}
