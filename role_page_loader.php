<?php
require_once __DIR__ . '/route_protection.php';

/**
 * Prepare a role page include (auth + absolute target path).
 * Call require on the returned path at TOP LEVEL of the entry script
 * so template variables remain true globals for isSecAllowed().
 */
function role_page_prepare(string $rootFile, array $allowedRoles = []): string
{
    if ($allowedRoles !== []) {
        route_require_login($allowedRoles);
    }

    $rootDir = __DIR__;
    $target = $rootDir . DIRECTORY_SEPARATOR . $rootFile;

    if (!is_file($target)) {
        http_response_code(404);
        echo 'Role page target not found.';
        exit;
    }

    return $target;
}

/**
 * Rewrite root-relative asset paths when the entry URL lives in a subfolder
 * (e.g. warehouse_head/dashboard.php).
 */
function role_page_rewrite_assets(string $html): string
{
    return str_replace(
        [
            'href="css/',
            'href="images/',
            'src="java/',
            'src="images/',
            "href='css/",
            "href='images/",
            "src='java/",
            "src='images/",
        ],
        [
            'href="../css/',
            'href="../images/',
            'src="../java/',
            'src="../images/',
            "href='../css/",
            "href='../images/",
            "src='../java/",
            "src='../images/",
        ],
        $html
    );
}

/**
 * @deprecated Prefer top-level require via role_page_prepare() + role_page_rewrite_assets().
 * Kept for compatibility; still suffers local-scope variable issues for globals.
 */
function renderRolePage(string $rootFile, array $allowedRoles = []): void
{
    $target = role_page_prepare($rootFile, $allowedRoles);
    $rootDir = __DIR__;
    $previousDir = getcwd();
    chdir($rootDir);
    ob_start();
    require $target;
    $html = ob_get_clean();
    if ($previousDir !== false) {
        chdir($previousDir);
    }
    echo role_page_rewrite_assets($html);
}
