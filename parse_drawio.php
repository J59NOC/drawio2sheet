<?php

define("AP_RADIUS", 7);
define("INFO_SOCKET_RADIUS", 6);

define("OBJECT_TYPE_AP", 1);
define("OBJECT_TYPE_SW", 2);
define("OBJECT_TYPE_INFO_SOCKET", 3);


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
    // "P-F2",
    // "P-F3",
    // "P-F4",
];

foreach($xml->diagram as $page){
    // 処理対象以外のページはスキップ
    if(!in_array($page["name"], $target_pages)){ continue; }

    // ページ（フロア）の情報を初期化
    $objects = [
        "ap" => [],
        "cable" => [],
        "info_socket" => [],
        "sw" => [],
    ];

    // // drawio上のidをkey, レイヤー名をvalueに持つ配列
    // $layers = [];

    // APレイヤのグループ化されたオブジェクトの親情報
    // key: そのオブジェクトのid, value: "arrow", "ellipse", "array"
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
            if($parent_id === $layer["id"] && preg_match("/^ellipse;/", $object["style"]) && preg_match("/^(<[^\/]+>)+(.+?)(<\/[^\/]+>)+$/", $object["value"], $m)){
                $devices[$id] = [
                    "type" => OBJECT_TYPE_INFO_SOCKET,
                    "text" => $m[2],
                    "ellipse" => [
                        "x" => round((string)$object->mxGeometry["x"]) + INFO_SOCKET_RADIUS,
                        "y" => round((string)$object->mxGeometry["y"]) + INFO_SOCKET_RADIUS,
                    ],
                ];
            }
            break;
        
        case "AP":
            // レイヤーに直接配置されたグループオブジェクト
            if($parent_id === $layer["id"] && preg_match("/^group(;|$)/", $object["style"])){
                $devices[$id] = [
                    "type" => OBJECT_TYPE_AP,
                    "arrow" => null,
                    "ellipse" => null,
                    "text" => null,
                    "position" => null,
                    "offset" => [ $id => [ "x" => round((string)$object->mxGeometry["x"]), "y" => round((string)$object->mxGeometry["y"]) ] ],
                ];
            }
            
            // それ以外のグループオブジェクト
            if(preg_match("/^group(;|$)/", $object["style"])){
                // 2段目は isset($devices[$parent_id]) で確認できるため計算量は増えてしまうが3段目以降と処理を共通化
                foreach($devices as $key => $group){
                    // groupごとに、当グループの親グループを含むか確認、含む場合は座標を計算してoffsetに追加
                    if(isset($group["offset"][$parent_id])){
                        $x = $group["offset"][$parent_id]["x"] + round((string)($object->mxGeometry["x"]?? 0));
                        $y = $group["offset"][$parent_id]["y"] + round((string)($object->mxGeometry["y"]?? 0));
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
                        $x = $group["offset"][$parent_id]["x"] + round($object->mxGeometry["width"]/2);
                        $y = $group["offset"][$parent_id]["y"] + round($object->mxGeometry["height"]/2);
                        $devices[$key]["position"] = [
                            "x" => $group["offset"][$parent_id]["x"] + AP_RADIUS,
                            "y" => $group["offset"][$parent_id]["y"] + AP_RADIUS,
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
                        // オブジェクトの中心点を保存
                        $devices[$key]["text"] = (string)$object["value"];
                    }
                }
            }
            break;
        
        case "ケーブル配線":

            break;
        
        case "L2/L3機器":

            break;
        }
    }

}

var_dump($devices);

// 現在のメモリ使用量（バイト）
$currentBytes = memory_get_usage();
// ピーク時のメモリ使用量（バイト）
$peakBytes = memory_get_peak_usage();

// MBに変換して出力
echo '現在のメモリ使用量: ' . round($currentBytes / 1024 / 1024, 2) . ' MB' . PHP_EOL;
echo 'ピーク時のメモリ使用量: ' . round($peakBytes / 1024 / 1024, 2) . ' MB' . PHP_EOL;


function add_ap_group($id, $parent_id){
    global $devices;
}