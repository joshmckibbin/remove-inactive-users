window.onload = function() {
    const btn = document.getElementById('submit-btn');
    if ( btn !== null ) {
        btn.onclick = function() {
            const verify = confirm('~~~ WARNING! ~~~\nYou are about to remove site users.\nThis process is irreversible.');

            if (verify) {
                document.getElementById('remove-inactive-users-form').submit();
            }

            return false;
        }
    }
}
