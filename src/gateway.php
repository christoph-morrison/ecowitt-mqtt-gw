<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);

const CONVERT_BARBARIAN_UNITS = true;
const MQTT_TOPIC_SEPARATOR = '/';

require_once __DIR__ . '/vendor/autoload.php';

$dumpfile = time() . '.ecowitt-data.json';

$toLog['env'] = array(
    'POST'      => $_POST,
    'GET'       => $_GET,
    'SERVER'    => $_SERVER,
    'ENV'       => $_ENV,
    'POST_RAW'  => file_get_contents('php://input'),
);

function get_var(string $key, $source = false, bool $convert = false, string $convert_type = '') {
    if ($source === false) {
        $source = $_POST;
    }

    if (!array_key_exists($key, $source)) {
        return false;
    }

    $return_value = $source[$key];

    if ($convert === true) {
        if ($convert_type === 'wm2lx') {
            return $return_value / 0.0079;
        }

        if ($convert_type === 'fahrenheit2celsius') {
            return ($return_value - 32) * 5/9;
        }

        if ($convert_type === 'mph2kmh') {
            return  ($return_value / 1.609344);
        }

        if ($convert_type === 'inHg2Pa') {
            return ($return_value * 33.8638816);
        }

        if ($convert_type === 'in2mm') {
            return ($return_value * 25.4);
        }
    }

    return $return_value;
}

$data   = array();
$dict   = null;
$source = $_POST;

foreach (range(1,4) as $idx) {
    if (array_key_exists("leak_ch" . $idx, $source)) {
        $dict['water_leak_sensors'][$idx] = array(
            'leak'     => (double) get_var('leak_ch' . $idx),
            'battery'  => (double) get_var('leakbatt' . $idx),
        );
    }

    if (array_key_exists("pm25_ch" . $idx, $source)) {
        $dict['air_quality_sensors'][$idx] = array(
            'pm25'    => (double) get_var('pm25_ch' . $idx),
            'avg_24h' => (double) get_var('pm25_avg_24h_ch' . $idx),
            'battery' => (double) get_var('pm25batt' . $idx),
        );
    }
}

foreach (range(1,8) as $idx) {
    if (array_key_exists("soilmoisture" . $idx, $source)) {
        $dict['soil_moisture_sensors'][$idx] = array(
            'moisture' => (double) get_var('soilmoisture' . $idx),
            'battery'  => (double) get_var('soilbatt' . $idx),
        );
    }

    if (array_key_exists('temp' . $idx . 'f', $source)) {
        $dict['indoor_multichannel_sensors'][$idx] = array(
            'temperature' => (double) get_var('temp' . $idx . 'f', false, CONVERT_BARBARIAN_UNITS, 'fahrenheit2celsius'),
            'humidity'    => (double) get_var('humidity' . $idx),
            'battery'     => (int) get_var('batt' . $idx),
        );
    }
}

if (array_key_exists('lightning', $source)) {
    $dict['lightning'] = array(
        'distance'  => (double) get_var('lightning'),
        'time'      => (get_var('lightning_time') !== '') ?: (double) 0,
        'count'     => (int) get_var('lightning_num'),
        'battery'   => (int) get_var('wh57batt'),
    );
}

$dict['wind'] = array(
    'direction' => (double) get_var('winddir'),
    'speed'     => (double) get_var('windspeedmph', false, CONVERT_BARBARIAN_UNITS, 'mph2kmh'),
    'gust'      => (double) get_var('windgustmph', false, CONVERT_BARBARIAN_UNITS, 'mph2kmh'),
#    'direction_10m_avg' => get_var('winddir_avg10m'),
#    'speed_10m_avg' => get_var('windspdmph_avg10m', false, true, 'mph2kmh'),
);

$dict['rain'] = array(
    'event'     => (double) get_var('eventrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'rate'      => (double) get_var('rainratein', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'hourly'    => (double) get_var('hourlyrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'daily'     => (double) get_var('dailyrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'weekly'    => (double) get_var('weeklyrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'monthly'   => (double) get_var('monthlyrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'yearly'    => (double) get_var('yearlyrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
    'total'     => (double) get_var('totalrainin', false, CONVERT_BARBARIAN_UNITS, 'in2mm'),
);

$dict['solar'] = array(
    'uv'        => (int) get_var('uv'),
    'radiation' => (double) get_var('solarradiation', false, CONVERT_BARBARIAN_UNITS, 'wm2lx'),
    'power'     => (double) get_Var('solarradiation'),
);

$dict['station'] = array(
    'battery'     => (int)    get_var('wh65batt'),
    'temperature' => (double) get_var('tempf', false, CONVERT_BARBARIAN_UNITS, 'fahrenheit2celsius'),
    'humidity'    => (int)    get_var('humidity'),
);

$dict['pressure'] = array(
    'absolute' => (double) get_var('baromabsin', false, CONVERT_BARBARIAN_UNITS, 'inHg2Pa'),
    'relative' => (double) get_var('baromrelin', false, CONVERT_BARBARIAN_UNITS, 'inHg2Pa'),
);

$dict['gateway'] = array(
    'passkey'       => get_var('PASSKEY'),
    'frequency'     => get_var('freq'),
    'model'         => get_var('model'),
    'type'          => get_var('stationtype'),
    'datetime_utc'  => get_var('dateutc'),
    'temperature'   => (double) get_var('tempinf', false, CONVERT_BARBARIAN_UNITS, 'fahrenheit2celsius'),
    'humidity'      => (double) get_var('humidityin'),
);

# log data
$toLog['dict'] = $dict;
file_put_contents("log/$dumpfile", json_encode($toLog, JSON_PRETTY_PRINT));

if (is_array($dict)) {
    $mqtt = new \PhpMqtt\Client\MqttClient('message-broker.fritz.box', 1883, 'ecowitt-mqtt-gw');
    $mqtt->connect();
}

$topic = "hab/devices/sensors/environment/weatherstation/ecowitt-" . substr(get_var('PASSKEY'), 0, 6);

foreach ($dict as $subtopic => $subdevice) {
    if (is_array($subdevice)) {
        foreach ($subdevice as $device => $subdevice_values) {
            $mqtt->publish(
                $topic . MQTT_TOPIC_SEPARATOR . $subtopic . MQTT_TOPIC_SEPARATOR . $device,
                json_encode($subdevice_values,
                    JSON_PRETTY_PRINT
                ),
                0);
        }
    } else {
        $mqtt->publish($topic. MQTT_TOPIC_SEPARATOR . $subtopic, json_encode($subdevice, JSON_PRETTY_PRINT), 0);
    }

}

$mqtt->disconnect();


