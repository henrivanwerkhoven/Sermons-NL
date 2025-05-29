<?php
if(defined('ABSPATH')){
    // this script should be called directly as an image, it does not require the wordpress lib
    header("HTTP/1.1 500 Internal Server Error");
    die("Do not include/require icon.php from wordpress");
}
// the script will output an svg image or a status-400 bad request if the wrong parameters are provided
// checking the input will prevent malicious input
// phpcs:ignore WordPress.Security
if(!isset($_GET['c']) || !preg_match("/^[0-9a-fA-F]{6}\$/", $_GET['c']) || !isset($_GET['m']) || !($_GET['m'] == 'a' || $_GET['m'] == 'v')){
    // $_GET['c'] should be a hexadecimal color without the #
    // $_GET['m'] should be 'a' or 'v'
    header("HTTP/1.1 400 Bad Request");
    die("Requested color or media type not supported.");
}
// phpcs:ignore WordPress.Security
$color = '#'.$_GET['c'];
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"25\" height=\"25\">";
// phpcs:ignore WordPress.Security
if($_GET['m'] == 'a'){
    // audio svg icon
    $svg .= "<g fill=\"{$color}\">".
    "<rect x=\"0\" y=\"8\" width=\"4.3\" height=\"9\" rx=\"1\" ry=\"1\"/>".
    "<polygon points=\"6,8.4 6,16.6 13,25 14,25 14,0 13,0\"/>".
    "</g>".
    "<g fill=\"none\" stroke=\"{$color}\" stroke-width=\"1.7\" stroke-linecap=\"round\">".
    "<path d=\"M18 2 Q30 12.5 18 23\"/>".
    "<path d=\"M17 5 Q25 12.5 17 20\"/>".
    "<path d=\"M16 8 Q20 12.5 16 17\"/>".
    "</g>";
}else{
    // video svg icon
    $svg .= "<g fill=\"{$color}\" stroke=\"{$color}\">".
    "<rect x=\"1\" y=\"1\" width=\"23\" height=\"15\" stroke-width=\"2\" fill=\"white\" rx=\"1\" ry=\"1\"/>".
    "<rect x=\"3.5\" y=\"3.5\" width=\"18\" height=\"10\" stroke=\"none\"/>".
    "<line y1=\"16\" x1=\"50%\" y2=\"24\" x2=\"50%\" stroke-width=\"2\"/>".
    "<line x1=\"25%\" y1=\"24\" x2=\"75%\" y2=\"24\" stroke-width=\"2\" stroke-linecap=\"round\"/>".
    "</g>";
}
$svg .= "</svg>";
// output svg
header("Content-Type: image/svg+xml");
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
die($svg);
