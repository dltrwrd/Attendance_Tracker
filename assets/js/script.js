$(document).ready(function () {

    // Loading animation for forms
    $("form").on("submit", function () {
        $("#loadingModal").modal("show");
    });

    // Close modal when navigating away
    $(window).on("beforeunload", function () {
        $("#loadingModal").modal("hide");
    });

    // Password match validation for registration
    $("#registerForm").on("submit", function (e) {
        const password = $("#password").val();
        const confirmPassword = $("#confirm_password").val();

        if (password !== confirmPassword) {
            e.preventDefault();
            alert("Passwords do not match");
            $("#loadingModal").modal("hide");
        }
    });

    // CAPTCHA refresh
    $(".captcha-image").on("click", function () {
        $(this).attr(
            "src",
            $(this).attr("src").split("?")[0] + "?" + Math.random()
        );
    });

    // Password match validation
    $("#confirm_password").on("keyup", function () {
        if ($("#new_password").val() !== $("#confirm_password").val()) {
            $(this).addClass("is-invalid");
            $("#password-match-error").removeClass("d-none");
        } else {
            $(this).removeClass("is-invalid");
            $("#password-match-error").addClass("d-none");
        }
    });

    // Password strength indicator
    $("#new_password").on("keyup", function () {
        const password = $(this).val();
        let strength = 0;

        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[a-z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;

        $("#password-strength")
            .removeClass()
            .addClass("strength-" + strength);
    });
});
