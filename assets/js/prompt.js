$(document).ready(function () {
    $("#prompt")
        .delay(2000)
        .fadeOut(400, function () {
            $(this).remove();
        });
});
