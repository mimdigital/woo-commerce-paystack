/**
 * Paystack Admin JS
 */
const jQuery = window.jQuery
const ClipboardJS = window.ClipboardJS
const wc_paystack_admin = window.wc_paystack_admin

jQuery(($) => {
  /**
   * Paystack Settings Handler
   */
  var WCPaystackAdmin = {
    init: function () {
      // Initialize event handlers
      this.initApiKeyToggle()
      this.initClipboard()
      this.initExportTransactions()

      // Run initial setup
      this.setupApiKeyFields()
    },

    /**
     * Initialize API key toggle functionality
     */
    initApiKeyToggle: function () {
      // Handle test mode toggle change
      $(document).on("change", ".wc-paystack-testmode-toggle #woocommerce_paystack_testmode", () => {
        this.setupApiKeyFields()
      })

      // Also handle changes after AJAX completion (for when settings are saved)
      $(document).ajaxComplete(() => {
        this.setupApiKeyFields()
      })
    },

    /**
     * Initialize clipboard functionality for webhook URL
     */
    initClipboard: () => {
      if (typeof ClipboardJS !== "undefined") {
        try {
          var clipboard = new ClipboardJS(".wc-paystack-copy-btn")

          clipboard.on("success", (e) => {
            var $button = $(e.trigger)
            var originalText = $button.text()

            $button.text("Copied!").addClass("copied")

            setTimeout(() => {
              $button.text(originalText).removeClass("copied")
            }, 2000)

            e.clearSelection()
          })

          clipboard.on("error", (e) => {
            console.error("Copy failed:", e)

            var $button = $(e.trigger)
            var originalText = $button.text()

            $button.text("Failed").addClass("error")

            setTimeout(() => {
              $button.text(originalText).removeClass("error")
            }, 2000)
          })
        } catch (error) {
          console.error("ClipboardJS initialization failed:", error)
        }
      }
    },

    /**
     * Initialize export transactions functionality
     */
    initExportTransactions: () => {
      $("#wc-paystack-export-transactions").on("click", function (e) {
        e.preventDefault()

        if (typeof wc_paystack_admin !== "undefined") {
          if (!confirm(wc_paystack_admin.i18n.export_confirm)) {
            return
          }
        }

        var $button = $(this)
        var originalText = $button.text()

        // Show loading state
        $button.text("Exporting...").prop("disabled", true)

        // Submit the export form
        $("#wc-paystack-export-form").submit()

        // Reset button after a delay
        setTimeout(() => {
          $button.text(originalText).prop("disabled", false)
        }, 3000)
      })
    },

    /**
     * Setup API key fields visibility based on test mode
     */
    setupApiKeyFields: () => {
      var testMode = $(".wc-paystack-testmode-toggle #woocommerce_paystack_testmode").is(":checked")

      if (testMode) {
        // Show test keys, hide live keys
        $(".wc-paystack-test-keys").closest("tr").show()
        $(".wc-paystack-live-keys").closest("tr").hide()

        // Add visual indicator for test mode
        if (!$(".wc-paystack-testmode-toggle").hasClass("wc-paystack-testmode-active")) {
          $(".wc-paystack-testmode-toggle").addClass("wc-paystack-testmode-active")
        }
      } else {
        // Show live keys, hide test keys
        $(".wc-paystack-test-keys").closest("tr").hide()
        $(".wc-paystack-live-keys").closest("tr").show()

        // Remove visual indicator for test mode
        $(".wc-paystack-testmode-toggle").removeClass("wc-paystack-testmode-active")
      }
    },
  }

  // Initialize the admin handler
  WCPaystackAdmin.init()

  // Auto-refresh functionality for transaction status
  $(".wc-paystack-auto-refresh").on("click", function (e) {
    e.preventDefault()

    var $link = $(this)
    var $row = $link.closest("tr")
    var originalText = $link.text()

    // Show loading state
    $link.html('<span class="wc-paystack-loading"></span> Checking...')

    // Perform the refresh
    window.location.href = $link.attr("href")
  })

  // Enhanced form validation
  $('form[action*="wc-settings"]').on("submit", function () {
    var $form = $(this)
    var $publicKey = $form.find("#woocommerce_paystack_public_key")
    var $secretKey = $form.find("#woocommerce_paystack_secret_key")
    var $enabled = $form.find("#woocommerce_paystack_enabled")

    if ($enabled.is(":checked")) {
      if (!$publicKey.val().trim()) {
        alert("Please enter your Paystack Public Key.")
        $publicKey.focus()
        return false
      }

      if (!$secretKey.val().trim()) {
        alert("Please enter your Paystack Secret Key.")
        $secretKey.focus()
        return false
      }

      // Validate key format
      var publicKeyPattern = /^pk_(test_|live_)[a-zA-Z0-9]+$/
      var secretKeyPattern = /^sk_(test_|live_)[a-zA-Z0-9]+$/

      if (!publicKeyPattern.test($publicKey.val().trim())) {
        alert("Invalid Public Key format. Please check your key.")
        $publicKey.focus()
        return false
      }

      if (!secretKeyPattern.test($secretKey.val().trim())) {
        alert("Invalid Secret Key format. Please check your key.")
        $secretKey.focus()
        return false
      }
    }

    return true
  })

  // Test mode warning
  $("#woocommerce_paystack_testmode")
    .on("change", function () {
      var $testMode = $(this)
      var $notice = $(".wc-paystack-test-mode-notice")

      if ($testMode.is(":checked")) {
        if ($notice.length === 0) {
          $testMode
            .closest("tr")
            .after(
              '<tr class="wc-paystack-test-mode-notice">' +
                '<td colspan="2">' +
                '<div class="wc-paystack-notice notice-warning">' +
                "<p><strong>Test Mode is enabled.</strong> Use your test API keys and no real transactions will be processed.</p>" +
                "</div>" +
                "</td>" +
                "</tr>",
            )
        }
      } else {
        $notice.remove()
      }
    })
    .trigger("change")

  // Fee percentage validation
  $("#woocommerce_paystack_fee_percent").on("input", function () {
    var $input = $(this)
    var value = Number.parseFloat($input.val())

    if (isNaN(value) || value < 0 || value > 100) {
      $input.addClass("error")
      $input.next(".description").remove()
      $input.after('<p class="description error">Please enter a valid percentage between 0 and 100.</p>')
    } else {
      $input.removeClass("error")
      $input.next(".description.error").remove()
    }
  })

  // Bulk actions for transactions table
  if ($(".wp-list-table").length) {
    // Add bulk action functionality if needed
    $('.wp-list-table .check-column input[type="checkbox"]').on("change", function () {
      var $table = $(this).closest(".wp-list-table")
      var $bulkActions = $table.find(".bulkactions select")
      var checkedCount = $table.find('tbody .check-column input[type="checkbox"]:checked').length

      if (checkedCount > 0) {
        $bulkActions.prop("disabled", false)
      } else {
        $bulkActions.prop("disabled", true)
      }
    })
  }

  // Tooltip functionality
  $(".woocommerce-help-tip").hover(
    function () {
      var $tip = $(this)
      var tipText = $tip.attr("data-tip") || $tip.attr("title")

      if (tipText) {
        $tip.attr("title", "") // Remove default tooltip

        var $tooltip = $('<div class="wc-paystack-tooltip">' + tipText + "</div>")
        $("body").append($tooltip)

        var tipOffset = $tip.offset()
        $tooltip.css({
          position: "absolute",
          top: tipOffset.top - $tooltip.outerHeight() - 5,
          left: tipOffset.left + $tip.outerWidth() / 2 - $tooltip.outerWidth() / 2,
          zIndex: 9999,
        })
      }
    },
    () => {
      $(".wc-paystack-tooltip").remove()
    },
  )

  // Settings tabs functionality (if needed)
  $(".wc-paystack-settings-tabs").on("click", "a", function (e) {
    e.preventDefault()

    var $tab = $(this)
    var target = $tab.attr("href")

    // Update active tab
    $tab.closest(".wc-paystack-settings-tabs").find("a").removeClass("nav-tab-active")
    $tab.addClass("nav-tab-active")

    // Show/hide content
    $(".wc-paystack-settings-content").hide()
    $(target).show()
  })

  // Auto-save draft functionality for settings
  var settingsChanged = false
  $('form[action*="wc-settings"] input, form[action*="wc-settings"] select, form[action*="wc-settings"] textarea').on(
    "change",
    () => {
      settingsChanged = true
    },
  )

  $(window).on("beforeunload", () => {
    if (settingsChanged) {
      return "You have unsaved changes. Are you sure you want to leave?"
    }
  })

  $('form[action*="wc-settings"]').on("submit", () => {
    settingsChanged = false
  })

  // Enhanced search functionality for transactions
  var searchTimeout
  $("#filter-by-transaction-id, #filter-by-order-id, #filter-by-reference").on("input", function () {
    clearTimeout(searchTimeout)
    var $input = $(this)

    searchTimeout = setTimeout(() => {
      if ($input.val().length >= 3 || $input.val().length === 0) {
        // Auto-submit form after 500ms of no typing
        $input.closest("form").submit()
      }
    }, 500)
  })

  // Keyboard shortcuts
  $(document).on("keydown", (e) => {
    // Ctrl/Cmd + S to save settings
    if ((e.ctrlKey || e.metaKey) && e.which === 83) {
      var $settingsForm = $('form[action*="wc-settings"]')
      if ($settingsForm.length) {
        e.preventDefault()
        $settingsForm.submit()
      }
    }

    // Escape to close modals/tooltips
    if (e.which === 27) {
      $(".wc-paystack-tooltip").remove()
    }
  })

  // Initialize on page load
  $(window).on("load", () => {
    // Highlight any error fields
    $(".form-table input.error, .form-table select.error").each(function () {
      $(this).focus().blur()
    })

    // Auto-focus first empty required field
    $(".form-table input[required]:not([value]), .form-table select[required]").first().focus()
  })
})
