window.addEventListener("load", () => {

  const checkoutContainer = document.getElementById("checkout-container-div");
  if (checkoutContainer !== null) {
    const {checkoutKey, createPaymentUrl} = checkoutContainer.dataset;

    var request = new XMLHttpRequest();
    request.open(
      "POST",
      createPaymentUrl,
      true
    );
    request.onload = function () {
      const data = JSON.parse(this.response);
      if (!data.paymentId) {
        console.error("Error: Check output from create-payment.php");
        return;
      }
      const paymentId = data.paymentId;
      if (paymentId) {
        const checkoutOptions = {
          checkoutKey: checkoutKey,
          paymentId: paymentId,
          containerId: "checkout-container-div",
        };
        const checkout = new Dibs.Checkout(checkoutOptions);
        checkout.on("payment-completed", function (payload) {
          const paymentIdCompleted = payload.paymentId;
          const paymentObj = {'payload' : payload };
          if (paymentId === paymentIdCompleted) {
            document.querySelector("input[name='payment_reference_field']").value = JSON.stringify(paymentObj);
            document.getElementById("edit-submit").click();
          } else {
            console.log("payment id mismatch");
          }
        });
      } else {
        window.history.back();
      }
    };
    request.onerror = function () {
      console.error("connection error");
    };
    request.send();
  }
});
