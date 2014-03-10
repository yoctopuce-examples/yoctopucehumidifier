<?php
//Some global definition to acces the mysql database and find the Yoctopuce PHP library
define('FRIDAYDB_SERVER', 'localhost');
define('FRIDAYDB_NAME', 'fridaydb1');
define('FRIDAYDB_USER', 'root');
define('FRIDAYDB_PASSWORD', '');
define('YOCTOPUCE_LIB_PATH', '');
define('MIN_HUMIDITY', 45);
define('MAX_HUMIDITY', 55);

include(YOCTOPUCE_LIB_PATH . "yocto_api.php");
include(YOCTOPUCE_LIB_PATH . "yocto_temperature.php");
include(YOCTOPUCE_LIB_PATH . "yocto_humidity.php");
include(YOCTOPUCE_LIB_PATH . "yocto_pressure.php");
include(YOCTOPUCE_LIB_PATH . "yocto_display.php");
include(YOCTOPUCE_LIB_PATH . "yocto_relay.php");



$display = NULL;
$conn = mysql_connect(FRIDAYDB_SERVER, FRIDAYDB_USER, FRIDAYDB_PASSWORD);
$res = mysql_select_db(FRIDAYDB_NAME);


function Error($msg)
{
    global $display;
    /** @var $display YDisplay */
    if ($display != NULL) {
        $display->resetAll();
        // reteive the first layer
        /** @var $l0 YDisplayLayer */
        $l0 = $display->get_displayLayer(0);
        // output the error message
        $l0->consoleOut('ERROR:' . $msg);
    }
    die($msg);
}



function setHumidifierState($on=False)
{
    /** @var $humPower YRelay */
    /** @var $humSwitch YRelay */
    global $humPower, $humSwitch;
    // first we cut the power of the humidifier
    // to reset his state to off
    $humPower->pulse(YRelay::STATE_B,500);
    if ($on) {
        // if we want to set off the humidifier
        // we emmit a pulse on the power switch
        $humSwitch->pulse(YRelay::STATE_B,100);
    }

}


if (YAPI::RegisterHub("callback", $errmsg) != YAPI_SUCCESS)
    Error($errmsg);

/** @var $tempSensor YTemperature */
$tempSensor = YTemperature::FirstTemperature();
/** @var $humSenor YHumidity */
$humSenor = YHumidity::FirstHumidity();
/** @var $pressSensor YPressure */
$pressSensor = YPressure::FirstPressure();
/** @var $display YDisplay */
$display = YDisplay::FirstDisplay();
/** @var $humPower YRelay */
$humPower=YRelay::FindRelay("hum_power");
/** @var $humSwitch YRelay */
$humSwitch=YRelay::FindRelay("hum_switch");


if (!$humPower->isOnline() || !$humSwitch->isOnline()) {
    Error("Humidifier is not connected or badly configured");
}

$humidity = $humSenor->get_currentValue();
if ($humidity < MIN_HUMIDITY) {
    setHumidifierState(True);
    //switch On Humidifier
} else if ($humidity> MAX_HUMIDITY) {
    //switch Off Humidifier
    setHumidifierState(false);
}


if ($display->isOnline()) {
    $history = Array();
    for ($i = 0; $i < 96; $i++) {
        $history[$i] = array("temp" => 0.0, "hum" => 0.0, "press" => 0.0, "count" => 0);
    }

    $timestamp = time();
    $lastTemp = 0;
    $lastHum = 0;
    $lastPress = 0;

    // fetch data from dtatabse, group  by 15 min clusters
    $result = mysql_query("select * from humidity where timestamp>" . ($timestamp - 86400) . " order by timestamp");
    if (!$result)
        Error(mysql_error());

    while ($row = mysql_fetch_array($result)) {
        $lastTemp = $row["temperature"];
        $lastHum = $row["humidity"];
        $lastPress = $row["pressure"];
        $lasTimeStamp = $row["timestamp"];


        $index = (int)($timestamp - $row["timestamp"]) / 300;
        $history[$index]["temp"] += $lastTemp;
        $history[$index]["hum"] += $lastHum;
        $history[$index]["press"] += $lastPress;
        $history[$index]["count"] += 1;
    }

    // compute average
    $tmin = 999;
    $tmax = -999;

    for ($i = 0; $i < sizeof($history); $i++) {
        if ($history[$i]["count"] > 0) {
            $history[$i]["temp"] = $history[$i]["temp"] / $history[$i]["count"];
            $history[$i]["hum"] = $history[$i]["hum"] / $history[$i]["count"];
            $history[$i]["press"] = $history[$i]["press"] / $history[$i]["count"];
            if ($history[$i]["temp"] > $tmax)
                $tmax = $history[$i]["temp"];
            if ($history[$i]["temp"] < $tmin)
                $tmin = $history[$i]["temp"];
        }
    }

    $width = $display->get_displayWidth();
    $height = $display->get_displayHeight();

    Printf("Display size = $width*$height \n");


    /** @var $layer4 YDisplayLayer */
    $layer4 = $display->get_displayLayer(4);
    $layer4->reset();
    $layer4->hide();


    // altitude correction
    $Z = 500;
    if (isset($_GET['alt']))
        $Z = intval($_GET['alt']);
    printf("Altitude=$Z m\n");


    $altPress = round($lastPress + 1013.25 * (1 - pow(1 - (0.0065 * $Z / 288.15), 5.255)));


    $layer4->selectFont('Small.yfm');
    $layer4->drawText(0, 0, Y_ALIGN_TOP_LEFT, 'P:' . $altPress);
    $layer4->drawText(0, $height - 1, Y_ALIGN_BOTTOM_LEFT, $lastHum . '%');
    if ($height > 32)
        $layer4->drawText($width / 2, $height - 1, Y_ALIGN_BOTTOM_CENTER, date('H:i'));

    $middle = (($tmax + $tmin) / 2);

    $middleScale = 5 * round($middle / 5);
    print("max=$tmax min=$tmin middle= $middleScale");
    for ($i = $middleScale - 10; $i <= $middleScale + 10; $i += 5) {
        $layer4->drawText($width - 1, round(($height / 2) - 2 * ($i - $middle)), Y_ALIGN_CENTER_RIGHT, $i);
        $layer4->moveTo($width - 13, round(($height / 2) - 2 * ($i - $middle)));
        $layer4->lineTo($width - 12, round(($height / 2) - 2 * ($i - $middle)));
    }

    $i = 0;
    if ($history[$i]["count"] > 0)
        $layer4->moveTo($width - 15, round(($height / 2) - ($history[$i]["temp"] - $middle) * 2));

    for ($i = 0; $i < sizeof($history); $i++)
        if ($history[$i]["count"] > 0)
            $layer4->lineTo($width - $i - 15, round($height / 2 - ($history[$i]["temp"] - $middle) * 2));

    if ((time() - $lasTimeStamp) > 900) {
        $lastTemp = sprintf("No data since %d min", (time() - $lasTimeStamp) / 60);
    } else {
        $lastTemp = sprintf("%2.1f", $lastTemp);
        $layer4->selectFont('Medium.yfm');
    }


    $layer4->selectColorPen(0);
    $layer4->drawText($width / 2 - 1, $height / 2, Y_ALIGN_CENTER, $lastTemp);
    $layer4->drawText($width / 2 + 1, $height / 2, Y_ALIGN_CENTER, $lastTemp);
    $layer4->drawText($width / 2, $height / 2 + 1, Y_ALIGN_CENTER, $lastTemp);
    $layer4->drawText($width / 2, $height / 2 - 1, Y_ALIGN_CENTER, $lastTemp);
    $layer4->selectColorPen(0xffff);
    $layer4->drawText($width / 2, $height / 2, Y_ALIGN_CENTER, $lastTemp);


    $display->swapLayerContent(3, 4);
} else {
    Printf("WARNING:  No display function with MeteoDisplay logical name\n");
}

?>