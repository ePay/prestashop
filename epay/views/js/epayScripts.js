/**
 * Copyright (c) 2017. All rights reserved ePay A/S (a Bambora Company).
 *
 * This program is free software. You are allowed to use the software but NOT allowed to modify the software.
 * It is also not legal to do any changes to the software and distribute it in your own name / brand.
 *
 * All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
 *
 * @author    ePay A/S (a Bambora Company)
 * @copyright Bambora (http://bambora.com) (http://www.epay.dk)
 * @license   ePay A/S (a Bambora Company)
 */

$(document).ready(function () {
    $('[data-toggle="tooltip"]').tooltip();

    if ($("#epay_overlay").length > 0) {
        $("a#epay_inline").fancybox({
            "scrolling": false,
            "transitionIn": "elastic",
            "transitionOut": "elastic",
            "speedIn": 400,
            "speedOut": 200,
            "overlayShow": true,
            "hideOnContentClick": false,
            "hideOnOverlayClick": false,
            "helpers": {
                "overlay": { "closeClick": true }
            }
        });

        $("a#epay_inline").trigger("click");
    }

    (function () {
        var inputField = $("#epay_amount");
        var epayCapture = $("#epay_capture");
        var epayCredit = $("#epay_credit");
        var epayFormatError = $("#epay_format_error");

        inputField.keydown(function (e) {
            var digit = String.fromCharCode(e.which || e.keyCode);
            if (e.which !== 8 &&
                e.which !== 46 &&
                !(e.which >= 37 &&
                e.which <= 40) &&
                e.which !== 110 &&
                e.which !== 188 &&
                e.which !== 190 &&
                e.which !== 35 &&
                e.which !== 36 &&
                !(e.which >= 96 && e.which <= 106)) {
                var reg = new RegExp(/^(?:\d+(?:,\d{0,3})*(?:\.\d{0,2})?|\d+(?:\.\d{0,3})*(?:,\d{0,2})?)$/);
                if (!reg.test(digit)) {
                    return false;
                }
            }
            return true;
        });

        inputField.focus(function () {
            if (epayFormatError.css("display") !== "none") {
                epayFormatError.toggle();
            }
        });

        epayCapture.click(function () {
            return validateInputField();
        });

        epayCredit.click(function () {
            return validateInputField();
        });

        function validateInputField() {
            var reg = new RegExp(/^(?:[\d]+([,.]?[\d]{0,3}))$/);
            if (inputField.length > 0 && !reg.test(inputField.val())) {
                epayFormatError.toggle();
                return false;
            }
            return true;
        }
    })();
});
