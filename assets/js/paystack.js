jQuery(($) => {
  // hide any leftover "Pay Now" UI
  $("#wc-paystack-form, .wc-paystack-payment").hide()

  if (typeof wc_paystack_params === "undefined") {
    console.log("Paystack params not found")
    return
  }

  console.log("Paystack params loaded:", wc_paystack_params)

  const handler = PaystackPop.setup({
    key: wc_paystack_params.key,
    email: wc_paystack_params.email,
    amount: wc_paystack_params.amount,
    currency: wc_paystack_params.currency,
    ref: wc_paystack_params.ref,
    metadata: wc_paystack_params.metadata,
    callback_url: wc_paystack_params.callback_url,
    onClose: () => {
      console.log("Payment window closed")
      window.location = wc_paystack_params.onclose || "/cart/"
    },
    callback: (response) => {
      console.log("Payment callback received", response)

      // Show processing message
      $("body").append(
        '<div id="paystack-processing" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;justify-content:center;align-items:center;"><div style="background:white;padding:20px;border-radius:5px;text-align:center;"><p>Processing payment, please wait...</p><div style="width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;margin:10px auto;animation:spin 1s linear infinite;"></div></div></div>',
      )

      // Verify the payment via AJAX
      $.post(
        wc_paystack_params.ajax_url,
        {
          action: "wc_paystack_verify_payment",
          reference: response.reference,
          nonce: wc_paystack_params.nonce,
        },
        (res) => {
          console.log("Verification response:", res)
          if (res.success) {
            window.location = res.data.redirect
          } else {
            const errorMessage =
              res.data && res.data.message ? res.data.message : "Payment verification failed. Please try again."
            alert(errorMessage)
            window.location = wc_paystack_params.onclose || "/cart/"
          }
        },
      ).fail((xhr, status, error) => {
        console.error("Verification error:", error)
        console.error("Response:", xhr.responseText)
        alert("An error occurred while verifying your payment")
        window.location = wc_paystack_params.onclose || "/cart/"
      })
    },
  })

  // Add CSS for the spinner animation
  $("head").append(`
    <style>
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
    </style>
  `)

  // Auto-start on order-pay page load
  if (wc_paystack_params.auto_start !== false) {
    console.log("Auto-starting Paystack popup")
    setTimeout(() => {
      handler.openIframe()
    }, 1000) // Small delay to ensure everything is loaded
  }

  // Fallback if you ever render a button
  $("#paystack-payment-button").on("click", (e) => {
    e.preventDefault()
    handler.openIframe()
  })

  // Intercept WooCommerce AJAX "Place Order" for inline mode
  $("form.checkout").on("checkout_place_order_paystack", (e) => {
    e.preventDefault()
    handler.openIframe()
    return false // tell WC we handled it
  })
})
