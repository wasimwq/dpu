<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// SFDC URL
$sfdc_url = "https://business-agility-9703.my.salesforce-sites.com/services/apexrest/leadCreationAPI";

// Google Sheet URL
$google_sheet_url = "https://script.google.com/macros/s/AKfycbx07G71AyEUi-IyhHKfqgwPwkw0xPhEI6ol8_OfJ_ds7jtrFTZLyCcirKGg1BxoauaR/exec";

// DB connection
$conn = mysqli_connect("localhost", "dpuonline", "dpuonline", "dpuonline");
if (!$conn) {
    die("DB Connection Failed: " . mysqli_connect_error());
}

// ✅ Universal param function (POST + GET)
function getParam($key) {
    if (!empty($_POST[$key])) {
        return trim($_POST[$key]);
    } elseif (!empty($_GET[$key])) {
        return trim($_GET[$key]);
    } else {
        return '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSubmit'])) {

    // Form data
    $FirstName = mysqli_real_escape_string($conn, $_POST["fname"] ?? '');
    $EmailId   = mysqli_real_escape_string($conn, $_POST["email"] ?? '');
    $Phone     = mysqli_real_escape_string($conn, $_POST["phoneno"] ?? '');
    $Program = mysqli_real_escape_string($conn, $_POST["program"] ?? '');

    // ✅ UTM + Source (FIXED LOGIC)
    $SourceCampaign = getParam('SourceCampaign') ?: getParam('utm_campaign');
    $SourceContent  = getParam('SourceContent')  ?: getParam('utm_content');
    $SourceMedium   = getParam('SourceMedium')   ?: getParam('utm_medium');
    $utm_term    = getParam('utm_term')    ?: getParam('utm_term');
    $gclid          = getParam('gclid');

    // Referrer
    $SourceReferrerURL = $_SERVER['HTTP_REFERER'] ?? '';

    // ✅ Session store (important for multi-page tracking)
    $_SESSION['utm_campaign'] = $_SESSION['utm_campaign'] ?? $SourceCampaign;
    $_SESSION['utm_content']  = $_SESSION['utm_content'] ?? $SourceContent;
    $_SESSION['utm_medium']   = $_SESSION['utm_medium'] ?? $SourceMedium;
    $_SESSION['utm_term']  = $_SESSION['utm_term'] ?? $utm_term;
    $_SESSION['gclid']        = $_SESSION['gclid'] ?? $gclid;

    // Session fallback
    $SourceCampaign = $SourceCampaign ?: $_SESSION['utm_campaign'];
    $SourceContent  = $SourceContent  ?: $_SESSION['utm_content'];
    $SourceMedium   = $SourceMedium   ?: $_SESSION['utm_medium'];
    $utm_term    = $utm_term    ?: $_SESSION['utm_term'];
    $gclid          = $gclid          ?: $_SESSION['gclid'];

    // ✅ Duplicate protection
    $submission_key = $Phone . $EmailId;
    if (isset($_SESSION['last_submission']) && $_SESSION['last_submission'] === $submission_key) {
        header("Location: /thanks/?email=" . urlencode($EmailId));
        exit;
    }
    $_SESSION['last_submission'] = $submission_key;

    // Time
    date_default_timezone_set("Asia/Kolkata");
    $date = date('Y-m-d');
    $time = date('H:i:s');
    $created_at = $date . " " . $time;

    // ✅ Insert into DB
    $sql = "INSERT INTO DPU_Email_2026
    (fname, email, phone, program, SourceCampaign, SourceContent, SourceMedium, SourceReferrerURL, utm_term, gclid, date, time, created_at)
    VALUES
    ('$FirstName', '$EmailId', '$Phone', '$Program', '$SourceCampaign', '$SourceContent', '$SourceMedium', '$SourceReferrerURL', '$utm_term', '$gclid', '$date', '$time', '$created_at')";

    if (!mysqli_query($conn, $sql)) {
        die("DB Insert Error: " . mysqli_error($conn));
    }

    // ===========================
    // ✅ GOOGLE SHEET
    // ===========================
    $google_data = json_encode([
        "name" => $FirstName,
        "email" => $EmailId,
        "phone" => $Phone,
        "source_campaign" => $SourceCampaign,
        "source_content" => $SourceContent,
        "source_medium" => $SourceMedium,
        "referrer_url" => $SourceReferrerURL,
        "utm_term" => $utm_term,
        "gclid" => $gclid,
        "date" => $date,
        "time" => $time
    ]);

    $options = [
        'http' => [
            'header'  => "Content-Type: application/json",
            'method'  => 'POST',
            'content' => $google_data,
        ],
    ];

    $context = stream_context_create($options);
    file_get_contents($google_sheet_url, false, $context);

    // ===========================
    // ✅ SFDC
    // ===========================
    if (function_exists('curl_init')) {

        $sfdc_data = json_encode([
            "name" => $FirstName,
            "phone" => $Phone,
            "email" => $EmailId,
            "Lead_Vendor_Source" => "DPU",
            "EnquiredforProgram" => $Program,
            "EnquiredforUniversity" => "DPU",
            "SourceCampaign" => $SourceCampaign,
            "SourceContent" => $SourceContent,
            "SourceMedium" => $SourceMedium,
            "SourceIPAddress" => $_SERVER['REMOTE_ADDR'] ?? '',
            "LeadSource" => "MIDFUNNEL",
            "branch" => "NCR",
            "utm_term" => $utm_term,
            "mx_utm_gclid" => $gclid
        ]);

        sendToSFDC($sfdc_url, $sfdc_data);
    }

    // Redirect
    header("Location: /thanks/?email=" . urlencode($EmailId));
    exit;
}

// CURL function
function sendToSFDC($url, $json_data) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_data)
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}
?>