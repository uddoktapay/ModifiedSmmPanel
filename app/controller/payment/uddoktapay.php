<?php
if (!defined('PAYMENT')) {
    http_response_code(404);
    die();
}

$invoice_id = $_REQUEST['invoice_id'];
if (empty($invoice_id)) {
    $up_response = file_get_contents('php://input');
    $up_response_decode = json_decode($up_response, true);
    $invoice_id = $up_response_decode['invoice_id'];
}

if (empty($invoice_id)) {
    errorExit("Direct access is not allowed.");
}

$apiKey =  trim($methodExtras['api_key']);

$baseURL = rtrim(trim($methodExtras['api_url']), '/');
$apiSegmentPosition = strpos($baseURL, '/api/');

if ($apiSegmentPosition !== false) {
    $baseURL = substr($baseURL, 0, $apiSegmentPosition + 5);
} elseif (($apiSegmentPosition = strpos($baseURL, '/api')) !== false) {
    $baseURL = substr($baseURL, 0, $apiSegmentPosition + 4);
}

$apiUrl = $baseURL . 'verify-payment';

$invoice_data = [
    'invoice_id' => $invoice_id
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($invoice_data),
    CURLOPT_HTTPHEADER => [
        "RT-UDDOKTAPAY-API-KEY: " . $apiKey,
        "accept: application/json",
        "content-type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
    errorExit("cURL Error #:" . $err);
}


if (empty($response)) {
    errorExit("Invalid Response From Payment API.");
}

$data = json_decode($response, true);

if (!isset($data['status']) && !isset($data['metadata']['order_id'])) {
    errorExit("Invalid Response From Payment API.");
}

if (isset($data['status']) && $data['status'] == 'COMPLETED') {
    $orderId = $data['metadata']['order_id'];
    $userId = $data['metadata']['user_id'];
    $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:orderId");
    $paymentDetails->execute([
        "orderId" => $orderId
    ]);
    
    $userDetails = $conn->prepare("SELECT * FROM clients WHERE client_id=:userId");
    $userDetails->execute([
        "userId" => $userId
    ]);

    if ($paymentDetails->rowCount()) {
        $paymentDetails = $paymentDetails->fetch(PDO::FETCH_ASSOC);
        $userDetails = $userDetails->fetch(PDO::FETCH_ASSOC);
        if (
            !countRow([
                'table' => 'payments',
                'where' => [
                    'client_id' => $userId,
                    'payment_method' => $methodId,
                    'payment_status' => 3,
                    'payment_delivery' => 2,
                    'payment_extra' => $orderId
                ]
            ])
        ) {
            $paidAmount = floatval($paymentDetails["payment_amount"]);
            if ($paymentFee > 0) {
                $fee = ($paidAmount * ($paymentFee / 100));
                $paidAmount -= $fee;
            }
            if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
                $bonus = $paidAmount * ($paymentBonus / 100);
                $paidAmount += $bonus;
            }

            $update = $conn->prepare('UPDATE payments SET 
                    client_balance=:balance,
                    payment_status=:status, 
                    payment_delivery=:delivery WHERE payment_id=:id');
            $update->execute([
                'balance' => $userDetails["balance"],
                'status' => 3,
                'delivery' => 2,
                'id' => $paymentDetails['payment_id']
            ]);

            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
            $balance->execute([
                "balance" => $userDetails["balance"] + $paidAmount,
                "id" => $userDetails["client_id"]
            ]);
            header("Location: " . site_url("addfunds"));
            exit();
        } else {
            header("Location: " . site_url("addfunds"));
            exit();
        }
    } else {
        errorExit("Order ID not found.");
    }
}

header("Location: " . site_url("addfunds"));
exit();

http_response_code(405);
die();
