<?php
// Redirect any direct visits to the API directory back to the site root.
header('Location: /', true, 302);
exit;