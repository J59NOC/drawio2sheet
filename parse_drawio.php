<?php

// 図上の半径
define("RADIUS_AP", 7);
define("RADIUS_INFO_SOCKET", 6);

// $objectのtype、表にするときに始点にしたいオブジェクトほど値を大きくする
define("DEVICE_TYPE_AP", 1);
define("DEVICE_TYPE_INFO_SOCKET", 2);
define("DEVICE_TYPE_SW", 3);

// ケーブル長の係数、TODO: 修正
define("CABLE_COEFF", 1);

$filename = $argv[1];

if(!file_exists($filename)){
    echo "file not found: $filename" . PHP_EOL;
    exit(1);
}

echo "loading: $filename" . PHP_EOL;

$xml = simplexml_load_file($filename);
if($xml === false){
    echo "failed." . PHP_EOL;
    exit(1);
}

$target_pages = [
    "P-B1/F1",
    "P-F2",
    "P-F3",
    "P-F4",
];

// ケーブルの情報を保存する配列
$cables = [];

foreach($xml->diagram as $page){
    // 処理対象以外のページはスキップ
    if(!in_array($page["name"], $target_pages)){ continue; }

    echo "processing page: " . $page["name"] . PHP_EOL;

    // レイヤ上のデバイス（ケーブルの接続先）の情報を初期化
    $devices = [];

    // ページ内のオブジェクトを順次処理
    // ドキュメントがあるわけではないが、おそらく下記の実装とみて問題なさそう
    // ・レイヤーオブジェクトの後、そのレイヤーのオブジェクト、の繰り返し
    // ・任意のオブジェクトについて、parentのidのオブジェクトは必ず先に登場している
    foreach($page->mxGraphModel->root->mxCell as $object){
        // ルートオブジェクト
        if($object["id"] == "0"){ continue; }

        // レイヤーオブジェクト
        if($object["parent"] == "0" && preg_match("/^locked=[01];$/", $object["style"]) && isset($object["value"])){
            // $layers[(string)$object["id"]] = $object["value"];
            $layer = [ "id" => (string)$object["id"], "name" => (string)$object["value"] ];
            echo "processing layer: " . $layer["name"] . PHP_EOL;
            continue;
        }

        // id, parent_idを取得（そのままだとSimpleXMLのオブジェクトのためstringにキャスト）
        $id = (string)$object["id"];
        $parent_id = (string)$object["parent"];

        switch($layer["name"]){
        case "情報コンセント":
            if($parent_id === $layer["id"] && preg_match("/^ellipse;/", $object["style"]) && preg_match("/^(<[^\/]+>)*([!-~]+?)(<\/[^\/]+>)*$/", $object["value"], $m)){
                $devices[$id] = [
                    "type" => DEVICE_TYPE_INFO_SOCKET,
                    "text" => $m[2],
                    "position" => [
                        "x" => (float)$object->mxGeometry["x"] + RADIUS_INFO_SOCKET,
                        "y" => (float)$object->mxGeometry["y"] + RADIUS_INFO_SOCKET,
                    ],
                ];
                echo "info socket found: {$m[2]}" . PHP_EOL;
            }
            break;
        
        case "AP":
            // レイヤーに直接配置されたグループオブジェクト
            if($parent_id === $layer["id"] && preg_match("/^group(;|$)/", $object["style"])){
                $devices[$id] = [
                    "type" => DEVICE_TYPE_AP,
                    "arrow" => null,
                    "ellipse" => null,
                    "text" => null,
                    "position" => null,
                    "offset" => [
                        $id => [
                            "x" => (float)$object->mxGeometry["x"],
                            "y" => (float)$object->mxGeometry["y"],
                        ],
                    ],
                ];
            }
            
            // それ以外のグループオブジェクト
            if(preg_match("/^group(;|$)/", $object["style"])){
                // 2段目は isset($devices[$parent_id]) で確認できるため計算量は増えてしまうが3段目以降と処理を共通化
                foreach($devices as $key => $group){
                    // groupごとに、当グループの親グループを含むか確認、含む場合は座標を計算してoffsetに追加
                    if(isset($group["offset"][$parent_id])){
                        $x = $group["offset"][$parent_id]["x"] + (float)($object->mxGeometry["x"]?? 0);
                        $y = $group["offset"][$parent_id]["y"] + (float)($object->mxGeometry["y"]?? 0);
                        $devices[$key]["offset"][$id] = [ "x" => $x, "y" => $y ];
                        break;
                    }
                }
            }

            // groupに含まれるAPの円の中心を求める
            elseif(preg_match("/^ellipse;/", $object["style"])){
                foreach($devices as $key => $group){
                    if(isset($group["offset"][$parent_id])){
                        // 同じグループに2つ以上の円がある場合は無効
                        if($group["ellipse"] !== null){
                            $devices[$key]["ellipse"] = false;
                            break;
                        }
                        // オブジェクトの存在を記録
                        $devices[$key]["ellipse"] = true; 
                        // オブジェクトの中心点を保存
                        $x = $group["offset"][$parent_id]["x"] + (float)$object->mxGeometry["width"] / 2;
                        $y = $group["offset"][$parent_id]["y"] + (float)$object->mxGeometry["height"] / 2;
                        $devices[$key]["position"] = [
                            "x" => $group["offset"][$parent_id]["x"] + RADIUS_AP,
                            "y" => $group["offset"][$parent_id]["y"] + RADIUS_AP,
                        ];
                    }
                }
            }
            // groupに含まれるAPの矢印の存在を確認
            elseif(preg_match("/;shape=mxgraph\.arrows2\.arrow;/", $object["style"])){
                foreach($devices as $key => $group){
                    if(isset($group["offset"][$parent_id])){
                        // 同じグループに2つ以上の円がある場合は無効
                        if($group["arrow"] !== null){
                            $devices[$key]["arrow"] = false;
                            break;
                        }
                        // オブジェクトの存在を記録
                        $devices[$key]["arrow"] = true; 
                    }
                }
            }
            // groupに含まれるtextを取得する
            elseif(preg_match("/^text;/", $object["style"])){
                foreach($devices as $key => $group){
                    if(isset($group["offset"][$parent_id])){
                        // 同じグループに2つ以上のtextがある場合は無効
                        if($group["text"] !== null){
                            $devices[$key]["text"] = false;
                            break;
                        }
                        // オブジェクトのテキストを保存
                        $devices[$key]["text"] = (string)$object["value"];
                        echo "ap found: {$devices[$key]["text"]}" . PHP_EOL;
                    }
                }
            }
            break;
        
        case "L2/L3機器":
            if($parent_id === $layer["id"] && preg_match("/^rounded=1;/", $object["style"]) && preg_match("/^(<[^\/]+>)*([!-~]+?)(<\/[^\/]+>)*$/", $object["value"], $m)){
                $devices[$id] = [
                    "type" => DEVICE_TYPE_SW,
                    "text" => $m[2],
                    "position" => [
                        "x" => (float)$object->mxGeometry["x"],
                        "y" => (float)$object->mxGeometry["y"],
                    ],
                    "size" => [
                        "width"  => (float)$object->mxGeometry["width"],
                        "height" => (float)$object->mxGeometry["height"],
                    ]
                ];
                echo "sw found: {$m[2]}" . PHP_EOL;
            }
            break;
        
        case "ケーブル配線":
            if($parent_id === $layer["id"] && preg_match("/^endArrow=none;(.+;)*strokeWidth=2;.*#(ff00ff|FF00FF)/", $object["style"])){
                // 始点と終点の座標の保存    
                $start = [
                    "x" => (float)$object->mxGeometry->mxPoint[0]["x"],
                    "y" => (float)$object->mxGeometry->mxPoint[0]["y"],
                ];
                $end = [
                    "x" => (float)$object->mxGeometry->mxPoint[1]["x"],
                    "y" => (float)$object->mxGeometry->mxPoint[1]["y"],
                ];

                // ケーブル長の算出
                $length = 0;
                // 線が折れている
                if(isset($object->mxGeometry->Array) && (string)$object->mxGeometry->Array["as"] === "points"){
                    $length = 0;
                    $prev = $start;
                    foreach($object->mxGeometry->Array->mxPoint as $point){
                        $current = [
                            "x" => (float)$point["x"],
                            "y" => (float)$point["x"],
                        ];
                        $length += sqrt(pow($prev["x"] - $current["x"], 2) + pow($prev["y"] - $current["y"], 2));
                        $prev = $current;
                    }
                    $length += sqrt(pow($prev["x"] - $end["x"], 2) + pow($prev["y"] - $end["y"], 2));
                }
                // 線が折れていない
                else{
                    $length += sqrt(pow($start["x"] - $end["x"], 2) + pow($start["y"] - $end["y"], 2));
                }

                // 始点・終点デバイスの確認
                $start_device_name = "";
                $start_device_type = -1;
                $start_distance = 10000;  // 十分大きな値
                $end_device_name = "";
                $end_device_type = -1;
                $end_distance = 10000;  // 十分大きな値
                foreach($devices as $device){
                    switch($device["type"]){
                    case DEVICE_TYPE_AP:
                    case DEVICE_TYPE_INFO_SOCKET:
                        $radius = $device["type"] === DEVICE_TYPE_AP? RADIUS_AP: RADIUS_INFO_SOCKET;
                        // 始点の確認
                        if(($distance = is_point_in_ellipse($start["x"], $start["y"], $device["position"]["x"], $device["position"]["y"], $radius)) >= 0){
                            if($distance < $start_distance){
                                $start_distance = $distance;
                                $start_device_name = $device["text"];
                                $start_device_type = $device["type"];
                            }
                        }
                        // 終点の確認
                        if(($distance = is_point_in_ellipse($end["x"], $end["y"], $device["position"]["x"], $device["position"]["y"], $radius)) >= 0){
                            if($distance < $end_distance){
                                $end_distance = $distance;
                                $end_device_name = $device["text"];
                                $end_device_type = $device["type"];
                            }
                        }
                        break;

                    case DEVICE_TYPE_SW:
                        // 始点の確認
                        if(($distance = is_point_in_rectangle($start["x"], $start["y"], $device["position"]["x"], $device["position"]["y"], $device["size"]["width"], $device["size"]["height"])) >= 0){
                            if($distance < $start_distance){
                                $start_distance = $distance;
                                $start_device_name = $device["text"];
                                $start_device_type = $device["type"];
                            }
                        }
                        // 終点の確認
                        if(($distance = is_point_in_rectangle($end["x"], $end["y"], $device["position"]["x"], $device["position"]["y"], $device["size"]["width"], $device["size"]["height"])) >= 0){
                            if($distance < $end_distance){
                                $end_distance = $distance;
                                $start_device_name = $device["text"];
                                $start_device_type = $device["type"];
                            }
                        }
                        break;
                    }
                }

                // 保存処理
                // device typeでの並び替え
                if($start_device_type < $end_device_type){
                    [$start_device_name, $end_device_name] = [$end_device_name, $start_device_name];
                }
                // device_nameでの昇順ソート
                elseif(strcmp($end_device_name, $start_device_name) > 0){
                    [$start_device_name, $end_device_name] = [$end_device_name, $start_device_name];
                }
                // ケーブル情報を追加
                $cables[] = [
                    "id" => $id,
                    "floor" => $page["name"],
                    "start_device" => $start_device_name,
                    "end_device" => $end_device_name,
                    "length" => (int)round($length),
                ];
                echo "cable found: $start_device_name - $end_device_name" . PHP_EOL;
            }
            break;
        }
    }

}

// デバッグ
// var_dump($devices);
// var_dump($cables);

// 出力
$fp = fopen("output.csv", "w");
// タイトル
fputcsv($fp, ["ケーブルID", "フロア", "始点", "終点", "ケーブル長＠図面"]);
// データ
foreach($cables as $cable){
    fputcsv($fp, [
        $cable["id"],
        $cable["floor"],
        $cable["start_device"],
        $cable["end_device"],
        $cable["length"],
    ]);
}
fclose($fp);


// 巨大なdrawioファイルなので、メモリリークしないか確認
echo '現在のメモリ使用量: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
echo 'ピーク時のメモリ使用量: ' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

// ------------------- functions -------------------

// 任意の座標が、円形オブジェクトに含まれるか
function is_point_in_ellipse(float $x, float $y, float $center_x, float $center_y, float $radius): float{
    // 中心からの距離
    $distance = sqrt(pow($x - $center_x, 2) + pow($y - $center_y, 2));
    // 含まれる場合、中心からの距離、含まれない場合-1を返す
    return $distance <= $radius? $distance: -1;
}

// 任意の座標が、長方形オブジェクトに含まれるか
function is_point_in_rectangle(float $x, float $y, float $left_x, float $top_y, float $width, float $height): float{
    // 範囲外なら-1を返す
    if ( $x < $left_x || $x > $left_x + $width || $y < $top_y || $y > $top_y + $height) {
        return -1;
    }

    $center_x = $left_x + $width / 2;
    $center_y = $top_y + $height / 2;

    return sqrt( pow($x - $center_x, 2) + pow($y - $center_y, 2) );
}
