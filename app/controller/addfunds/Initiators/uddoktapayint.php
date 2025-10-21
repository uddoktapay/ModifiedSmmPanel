<?php
if (!defined('ADDFUNDS')) {
    http_response_code(404);
    die();
}

$apiKey = $methodExtras["api_key"];
$apiUrl = $methodExtras["api_url"];
$payeeName = $user["name"] ?: "User";
$payeeEmail = $user["email"] ?: "test@test.com";
$paymentURL = site_url("payment/" . $methodCallback);
$orderId = md5(RAND_STRING(5) . time());

$insert = $conn->prepare(
    "INSERT INTO payments SET
client_id=:client_id,
payment_amount=:amount,
payment_method=:method,
payment_mode=:mode,
payment_create_date=:date,
payment_ip=:ip,
payment_extra=:extra"
);

$insert->execute([
    "client_id" => $user["client_id"],
    "amount" => $paymentAmount,
    "method" => $methodId,
    "mode" => "Automatic",
    "date" => date("Y.m.d H:i:s"),
    "ip" => GetIP(),
    "extra" => $orderId
]);


$requestData = [
    'full_name'     => $payeeName,
    'email'         => $payeeEmail,
    'amount'        => $paymentAmount,
    'metadata'      => [
        'order_id' => $orderId,
        'user_id' => $user["client_id"]
    ],
    'redirect_url'  => $paymentURL,
    'return_type'   => 'GET',
    'cancel_url'    => site_url(""),
    'webhook_url'   => $paymentURL
];

$host = parse_url($apiUrl,  PHP_URL_HOST);
$postUrl = "https://{$host}/api/checkout-v2/global";

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $postUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_HTTPHEADER => [
        "RT-UDDOKTAPAY-API-KEY: " . $apiKey,
        "accept: application/json",
        "content-type: application/json"
    ],
]);

$upresponse = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);
if ($err) {
    errorExit("cURL Error #:" . $err);
} else {
    $result = json_decode($upresponse, true);
    if (isset($result['status']) && isset($result['payment_url'])) {
        $paymentUrl = $result['payment_url'];
        $redirectForm .= '<form method="GET" action=" ' . $paymentUrl . '" name="uddoktapayCheckoutForm">';
        $redirectForm .= '</form>
        <script type="text/javascript">
        document.uddoktapayCheckoutForm.submit();
        </script>';
    } else {
        errorExit($result['message']);
    }
}

$response["success"] = true;
$response["message"] = "Your payment has been initiated and you will now be redirected to the payment gateway.";
$response["content"] = $redirectForm;
