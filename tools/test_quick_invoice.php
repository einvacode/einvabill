<?php
// Disabled test utility. Re-enable only on development machines.
if (php_sapi_name() !== 'cli') {
    echo "Disabled in production. This CLI test must be run only on development hosts.\n";
    exit(1);
}
echo "Disabled in repository default. Contact admin to enable test utilities.\n";
exit(0);
