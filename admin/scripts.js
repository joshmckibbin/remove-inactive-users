const submitBtn = document.getElementById('submit-btn');
if ( submitBtn ) {
    submitBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const verify = confirm('~~~ WARNING! ~~~\nYou are about to remove site users.\nThis process is irreversible.');

        if (verify) {
            document.getElementById('remove-inactive-users-form').submit();
        }

        return false;
    });
}
