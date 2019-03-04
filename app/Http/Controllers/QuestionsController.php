<?php

namespace App\Services;

use App\Models\CustomerVolume;
use App\Models\OrderingTickets;
use App\Models\ProductSeriesDetail;
use App\Services\CommonService;
use App\Wxpay\WxPayApi;
use DB;
use Carbon\Carbon;
use App\Models\DeliveryWarehouse;
use App\Models\CustomerAddress;
use App\Models\CustomerPoints;
use App\Models\CustomerProfile;
use App\Models\DeliveryArea;
use App\Models\DeliveryExpress;
use App\Models\OrderingDelivery;
use App\Models\OrderingFirstorder;
use App\Models\OrderingOrderdetail;
use App\Models\OrderingOrderheader;
use App\Models\OrderingPayment;
use App\Models\OrderingUser;
use App\Models\ProductBase;
use App\Models\ProductInventory;
use App\Models\ProductInventorylog;
use App\Models\ProductPricebase;
use App\Models\SystemDatadictionary;
use App\Models\SystemRegion;
use App\Models\OrderingPackage;
use App\Models\OrderingInvoice;
use App\Models\OrderingSeriesdetail;
use App\Services\DeliveryService;
use App\Services\PaymentService;
use App\Services\ExpressService;
use Illuminate\Support\Collection;
use Log;
use Illuminate\Support\Facades\Cache;
use App\Services\AlipayService;
use App\Services\BlacklistService;
use App\Services\CartService;
use App\Invoice\MyAES;
use Illuminate\Support\Facades\Validator;
use EasyWeChat\Support\XML;

/**
 *  订单服务
 */
class OrderService
{

    public function __construct(CommonService $commonService)
    {
        $this->commonService = $commonService;
        $this->wxPayApi = app(WxPayApi::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->paymentService = app(PaymentService::class);
        $this->alipayService = app(AlipayService::class);
        $this->expressServive = app(ExpressService::class);
        $this->blacklistService = app(BlacklistService::class);
        $this->cartService = app(CartService::class);
    }

    /**
     *  获得sku产品
     * @param Request $request
     */
    public function getAdminAppSku($data, $platform = "wechat", $type = 1)
    {
        $sku = trim($data["sku"]);
        $orderProvinceId = isset($data["province_id"]) ? $data["province_id"] : 0; //订货人省份id
        $freightId = isset($data["freightId"]) ? intval($data["freightId"]) : 0; //快递公司id
        $result = [];
        $product = ProductBase::select("id")->where("SKU", $sku)->where("isdeleted", 0)->first();
        if (!empty($product)) {
            $result = $this->commonService->getProduct($product["id"], $orderProvinceId, $freightId, $platform, $type);
        }
        return $result;
    }

    /**
     * 确认订单
     */
    public function orderConfirm($data, $platform = "wechat", $type = 1)
    {
        $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //资格证号
        $cpid = $this->commonService->getCustomerProfileId($customerId, $platform); //客户id
        $orderer = $this->commonService->getOrdererInfo($cpid); //订货人信息
        $is_active = object_get($orderer, "is_active", 0);
        if (!$is_active) {
            $arr["status"] = false;
            return compact("arr");
        }
        $uid = $this->commonService->getOrderingUserId($platform, $type, $customerId); //订购用户id
        $orderProvinceId = isset($data["province_id"]) ? $data["province_id"] : 0; //订货人省份id
        $cacheKey = $this->commonService->getOrderCartsCacheKey($platform, $uid); //购物车缓存键
        $addrId = 0;
        if ($platform != OrderingUser::$platform[2]) {
            $addrId = $this->commonService->getOrderAddrId($platform, $uid); //订单地址id
            $prom = isset($data["prom"]) ? json_decode($data["prom"], true) : []; //赠品选择列表
        }
        $carts = Cache::get($cacheKey);
        $pids = empty($data["pids"]) ? "" : explode(",", $data["pids"]); //购物车选择产品id
        // $nums = empty($data["nums"]) ? 0 : intval($data["nums"]); //立即购买才有数量
        $nums = 0; //立即购买才有数量
        if (!empty($data["nums"])) {
            $validator = Validator::make($data, [
                'nums' => [
                    'required',
                    'regex:/^([0-9]|[1-9][0-9]*)$/'
                ],
            ]);
            if ($validator->fails()) {
                $arr = ['status' => false, 'msg' => '参数非法'];
                return compact("arr");
            }
            $nums = intval($data["nums"]);
        }
        $productIds = []; //购买产品id集合
        $products = []; //购物车列表
        $orderProducts = isset($data["products"]) ? $data["products"] : []; //订单产品列表(admin接口)
        $promotion = []; //促销列表
        $giftProducts = []; //赠送产品列表
        $quantitysum = 0; //数量总和
        $priceSum = 0; //价格总和
        $pointSum = 0; //价格总和
        $vpsum = 0; //vp总和
        $freightId = isset($data["freightId"]) ? intval($data["freightId"]) : 0; //快递公司id
        $discountamt = 0; //折扣价格
        $freightamt = null; //运费(B2C)
        $totalfreight = 0; //配送费(保险费 + B2C运费)
        $totalWeight = 0; //商品总重量
        $insurance = 0; //保险费
        $adjustAmount = 0; //其他调整应付金额
        $firsttotalprice = 0; //首单价格
        $firstTotalVp = 0; //首单VP
        $now = Carbon::now();
        $orderDetailData = []; //订单详情
        $months = $this->commonService->getMonths(); //订单月
        $receiptdate = []; //收货时间
        $totalWeight = 0; //计费重量(kg)
        $address = CustomerAddress::select("id", "receivername", "province_id", "district_id", "city_id", "address", "phoneno", "isdefault")
            ->where("id", $addrId)
            ->where("isdeleted", 0)
            ->first(); //收货地址
        if (!empty($address)) {
            $orderProvinceId = $address["province_id"];
            $address["Province"] = SystemRegion::select("name")->where("id", $orderProvinceId)->first();
            $address["City"] = SystemRegion::select("name")->where("id", $address["city_id"])->first();
            $address["District"] = SystemRegion::select("name")->where("id", $address["district_id"])->first();
        }
        $express = DeliveryArea::select("delivery_express.id", "delivery_express.insurance_rate", "delivery_express.name", "delivery_express.freightamt", "delivery_area.express_id", "delivery_express.freightamt")
            ->leftJoin("delivery_express", "delivery_area.express_id", "=", "delivery_express.id")
            ->where("delivery_area.province_id", $orderProvinceId)
            ->where("delivery_area.effectivedate", "<=", $now)
            ->where("delivery_area.expireddate", ">=", $now)
            ->where("delivery_area.isdeleted", 0)
            ->where("delivery_express.isdeleted", 0)
            ->groupBy("delivery_area.express_id")
            ->get(); //快递公司
//        $freightamt = $express->isEmpty() ? 0 : sprintf("%.2f", $express[0]["freightamt"]);
        if (empty($freightId)) {

            if ($express->isEmpty()) {
                $freightId = 0;
            } else {
                //默认用顺丰
                foreach ($express as $exp) {
                    if (object_get($exp, "name") == "顺丰速运") {
                        $freightId = object_get($exp, "id", 0);
                        break;
                    }
                }
                if (!$freightId) {
                    $freightId = $express[0]["id"];
                }
            }
            // $freightId = $express->isEmpty() ? 0 : $express[0]["id"];
        } //快递公司id

        if (!empty($freightId)) {
            $scheduleList = DeliveryExpress::select("delivery_schedule_list")->where("id", $freightId)->first();
            $scheduleList = explode(",", $scheduleList["delivery_schedule_list"]);
            $receiptdate = SystemDatadictionary::select("Code", "LabelCN")->where("Type", RECEIPTDATE)->whereIn("code", $scheduleList)->get(); //收货时间
        }
        $chooseExpress = DeliveryExpress::select("name", "freightamt", "insurance_rate", "fluctuate_rate")->where("id", $freightId)->first(); //选择快递公司
        $discount = CustomerPoints::selectRaw("sum(DiscountAmt) as DiscountAmt")
            ->where("orderinguser_id", $uid)
            ->where("effectivedate", "<=", $now)
            ->where("expireddate", ">=", $now)
            ->where("status", 1)
            ->where("isdeleted", 0)
            ->first();
        if (!empty($discount["discountamt"])) {
            $discountamt = $discount["discountamt"];
        }
        if (!empty($carts) && !empty($pids) && empty($nums)) {//购物车结算
            foreach ($carts["carts"] as $key => $cart) {
                if (in_array($key, $pids)) {
                    $productIds[] = $key;
                    $quantity = $cart["quantity"];
                    $product = $this->commonService->getProduct($key, $orderProvinceId, $freightId, $platform, $type);
                    $product_price = [
                        "Price" => object_get($product, 'price.Price'),
                        "Points" => object_get($product, 'price.Points'),
                        "vp" => object_get($product, 'price.vp'),
                    ];
                    if (object_get($product, 'price.Price')) {
                        $validator = Validator::make($product_price, [
                            'Price' => [
                                'required',
                                'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                            ],
                            'vp' => [
                                'required',
                                'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                            ],
                            'Points' => [
                                'required',
                                'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                            ],
                        ]);
                        if ($validator->fails()) {
                            $arr = ['status' => false, 'msg' => '参数非法'];
                            return compact("arr");
                        }
                    }
                    $product["quantity"] = $quantity;
                    $products[] = $product;
                    $orderDetailData[] = [
                        "product_id" => $key,
                        "quantity" => $quantity,
                        "UnitPrice" => $product["price"]["Price"],
                        "vp" => $product["price"]["vp"],
                        "Points" => $product["price"]["Points"],
                    ];
                    $quantitysum += $quantity;
                    $priceSum += $product["price"]["Price"] * $quantity;
                    $pointSum += $product["price"]["Points"] * $quantity;
                    $vpsum += $product["price"]["vp"] * $quantity;
                    $vpsum = sprintf('%.2f', $vpsum);
                    $pointSum = sprintf('%.2f', $pointSum);
                }
            }
            if (empty($products)) {
                $arr = [];
                return compact("arr");
            }
        } elseif (!empty($pids) && !empty($nums)) {//立即购买结算
            $product = $this->commonService->getProduct($pids[0], $orderProvinceId, $freightId, $platform, $type);
            $product_price = [
                "Price" => object_get($product, 'price.Price'),
                "Points" => object_get($product, 'price.Points'),
                "vp" => object_get($product, 'price.vp'),
            ];
            if (object_get($product, 'price.Price')) {
                $validator = Validator::make($product_price, [
                    'Price' => [
                        'required',
                        'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                    ],
                    'Points' => [
                        'required',
                        'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                    ],
                    'vp' => [
                        'required',
                        'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                    ],
                ]);
                if ($validator->fails()) {
                    $arr = ['status' => false, 'msg' => '参数非法'];
                    return compact("arr");
                }
            }
            $productIds[] = $pids[0];
            $product["quantity"] = $nums;
            $products[] = $product;
            $orderDetailData[] = [
                "product_id" => $product["id"],
                "quantity" => $nums,
                "UnitPrice" => $product["price"]["Price"],
                "Points" => $product["price"]["Points"],
                "vp" => $product["price"]["vp"],
            ];
            $quantitysum += $nums;
            $priceSum += $product["price"]["Price"] * $nums;
            $pointSum += $product["price"]["Points"] * $nums;
            $vpsum += $product["price"]["vp"] * $nums;
        } else if ($platform == OrderingUser::$platform[2]) {//admin接口调用
//            $lastmonth=getMonth(1);
//            $months[]=$lastmonth;
            //获取手工添加订单的月份
            $months = $this->commonService->adminGetMonths();
            foreach ($orderProducts as $key => $orderProduct) {
                if (!isset($orderProduct["id"]))
                    continue;
                $orderProductId = $orderProduct["id"];
                $productIds[] = $orderProductId;
                $quantity = isset($orderProduct["quantity"]) ? (int)$orderProduct["quantity"] : 0;
                if (empty($quantity))
                    continue;
                $product = $this->commonService->getProduct($orderProductId, $orderProvinceId, $freightId, $platform, $type);
                if (empty($product))
                    continue;
                $product["quantity"] = $quantity;
                $products[] = $product;
                $orderDetailData[] = [
                    "product_id" => $product["id"],
                    "quantity" => $quantity,
                    "UnitPrice" => $product["price"]["Price"],
                    "Points" => $product["price"]["Points"],
                    "vp" => $product["price"]["vp"],
                ];
                $quantitysum += $quantity;
                $priceSum += $product["price"]["Price"] * $quantity;
                $pointSum += $product["price"]["Points"] * $quantity;
                $vpsum += $product["price"]["vp"] * $quantity;
            }
        } else {
            $arr = [];
            return compact("arr");
        }
//        if ($platform != OrderingUser::$platform[2]) {
        $promotion = $this->commonService->getPromotion($orderDetailData, $orderProvinceId, $priceSum, $vpsum, $cpid); //促销列表
//         }
        //手工订单促销和运费问题
        $produc_sku = isset($data['products'][0]['sku']) ? $data['products'][0]['sku'] : 0;
        if ($platform == OrderingUser::$platform[2] && in_array($produc_sku, ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {
            $promotion = null;
        }
        if ($platform == OrderingUser::$platform[2]) {
            $promotionList = [];
            if (!empty($promotion)) {
                foreach ($promotion as $promList) {
                    $ptypes = !empty($promList["type"]) ? explode(",", $promList["type"]) : []; //优惠方式
                    if (!empty($ptypes)) {
                        $apiece = array_get($promList, "apiece", 0);
                        $apieceStr = $apiece == 0 ? "已满 " : "每满 ";
                        if ($promList["promType"] == "product") {
                            $product = ProductBase::select("id", "name", "sku")
                                ->where("id", array_get($promList, "prom_product_id", 0))
                                ->where("isdeleted", 0)
                                ->first();
                            if (empty($product)) {
                                continue;
                            }
                            $typeStr = $apieceStr . intval($promList["amount"]) . "个" . object_get($product, "name") . "，送赠品。";
                        } else {
                            $typeStr = $promList["promType"] == "vp" ? "积分" : "元";
                            $typeStr = $apieceStr . sprintf("%.2f", $promList["amount"]) . $typeStr;
                        }
                        foreach ($ptypes as $ptype) {
                            if ($ptype == "freeShipping" && ($promList["isfreepostage"] == 1)) {//免邮
                                $withoutregionId = !empty($promList["withoutregion_id"]) ? explode(",", $promList["withoutregion_id"]) : []; //不免邮地区
                                if (!in_array($orderProvinceId, $withoutregionId)) {
                                    $freightamt = 0;
                                    $typeStr .= "，免邮";
                                }
                            } else {
                                if ($ptype == "lessCash") {//减免金额
                                    $discountamt += ($promList["discountamt"] * $promList["apieceNum"]);
                                    $typeStr .= "，减现金" . sprintf("%.2f", $promList["discountamt"]);
                                } else {
                                    if ($ptype == "discount") {//折扣
                                        $discountamt += $priceSum * (1 - ($promList["discountrate"] / 10));
                                        $typeStr .= "，享" . sprintf("%.2f", $promList["discountrate"]) . " 折";
                                    }
                                }
                            }
                        }
                        $promotionList[] = $typeStr . "；";
                    }
                }
            }
        } else {
            $promotionList = [];
            if (!empty($promotion)) {
                foreach ($promotion as $promList) {
                    $ptypes = !empty($promList["type"]) ? explode(",", $promList["type"]) : []; //优惠方式
                    if (!empty($ptypes)) {
                        $apiece = array_get($promList, "apiece", 0);
                        $apieceStr = $apiece == 0 ? "已满 " : "每满 ";
                        if ($promList["promType"] == "product") {
                            $product = ProductBase::select("id", "name", "sku")
                                ->where("id", array_get($promList, "prom_product_id", 0))
                                ->where("isdeleted", 0)
                                ->first();
                            if (empty($product)) {
                                continue;
                            }
                            $typeStr = $apieceStr . intval($promList["amount"]) . "个" . object_get($product, "name");
                        } else {
                            $typeStr = $promList["promType"] == "vp" ? "积分" : "元";
                            $typeStr = $apieceStr . sprintf("%.2f", $promList["amount"]) . $typeStr;
                        }
                        foreach ($ptypes as $ptype) {
                            if ($ptype == "lessCash") {//减免金额
                                $discountamt += ($promList["discountamt"] * $promList["apieceNum"]);
                                $typeStr .= "，减现金" . sprintf("%.2f", $promList["discountamt"]);
                            } else {
                                if ($ptype == "discount") {//折扣
                                    $discountamt += $priceSum * (1 - ($promList["discountrate"] / 10));
                                    $typeStr .= "，享" . sprintf("%.2f", $promList["discountrate"]) . " 折";
                                } else {
                                    if ($ptype == "gifts") {//赠品
                                        $gids = !empty($promList["freeproduct_id"]) ? explode(",", $promList["freeproduct_id"]) : []; //产品id列表
                                        $choiceProdNum = 0; //选择赠品数量
                                        if (!empty($gids)) {
                                            $prom_index = -1;
                                            foreach ($prom as $key => $promItems) {
                                                if ($promItems["id"] == $promList["id"]) {
                                                    $prom_index = $key;
                                                    break;
                                                }
                                            }
                                            $prom_gids = isset($prom[$prom_index]) && !empty($prom[$prom_index]["pids"]) ? explode(",", $prom[$prom_index]["pids"]) : []; //选择赠品id列表
//                                if (empty($prom)) {
//                                    $prom_gids = array_slice($gids, 0, ($promList["freeproduct_qty"] == 0 ? null : $promList["freeproduct_qty"]));
//                                }
                                            foreach ($gids as $gid) {
                                                if ($promList["freeproduct_qty"] > 0 && $choiceProdNum == $promList["freeproduct_qty"]) {
                                                    break;
                                                }
                                                $giftStock = $this->commonService->getStock($gid, $orderProvinceId, $freightId, $platform, $type); //产品库存
                                                $orderNum = array_get($promList, "apieceNum");
                                                if (in_array($gid, $productIds)) {
                                                    foreach ($orderDetailData as $list) {
                                                        if ($list["product_id"] == $gid) {
                                                            $orderNum += $list["quantity"];
                                                            break;
                                                        }
                                                    }
                                                }
                                                $product = $this->commonService->getProduct($gid, $orderProvinceId, $freightId, $platform, $type);
                                                if (!empty($product) && (!empty($prom) && in_array($gid, $prom_gids)) || empty($prom) && (($promList["freeproduct_qty"] == 0 && $orderNum <= $giftStock) || ($promList["freeproduct_qty"] > 0 && $choiceProdNum < $promList["freeproduct_qty"] && $orderNum <= $giftStock))
                                                ) {//有可扣库存
                                                    $product["quantity"] = array_get($promList, "apieceNum");
                                                    $product["price"]["Points"] = 0;
                                                    $product["price"]["Price"] = 0;
                                                    $product["price"]["vp"] = 0;
//                                        $products[] = $product; //赠品
                                                    $giftProducts[] = $product; //赠品
                                                    $orderDetailData[] = [
                                                        "product_id" => $gid,
                                                        "quantity" => $product["quantity"],
                                                        "UnitPrice" => 0,
                                                        "vp" => 0,
                                                    ];
                                                    $choiceProdNum++;
                                                }
                                            }
                                            $typeStr .= count($giftProducts) > 0 ? "，送赠品" : "";
                                        }
                                    } else {
                                        if ($ptype == "freeShipping" && ($promList["isfreepostage"] == 1)) {//免邮
                                            $withoutregionId = !empty($promList["withoutregion_id"]) ? explode(",", $promList["withoutregion_id"]) : []; //不免邮地区
                                            if (!in_array($orderProvinceId, $withoutregionId)) {
                                                $freightamt = 0;
                                                $typeStr .= "，免邮";
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $promotionList[] = $typeStr . "；";
                    }
                }
            }
        }
//        if ($freightamt === null) {//不免邮（计算运费）
//            $freightamt = $this->deliveryService->getExpressFee($orderDetailData, $orderProvinceId, $freightId);
//            $insurance = ($priceSum * (empty($chooseExpress) ? 0 : $chooseExpress["insurance_rate"])); //保险费 (产品总金额 * 保险费费率)
//            $insurance = sprintf("%.2f", $insurance);
//            $totalfreight = $insurance + $freightamt; //配送费 (保险费 + B2C运费)
//        } else {
//            $totalfreight = $freightamt;
//        }
        if ($platform == OrderingUser::$platform[2] && in_array($produc_sku, ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {
            $freightamt = 0;
        }
        $freightFee = $this->deliveryService->getExpressFee($orderDetailData, $orderProvinceId, $freightId);
        $insurance = ($priceSum * (empty($chooseExpress) ? 0 : $chooseExpress["insurance_rate"])); //保险费 (产品总金额 * 保险费费率)
        $insurance = sprintf("%.2f", $insurance);
        $freightamt_actual = $insurance + $freightFee; //配送费 (保险费 + B2C运费)
        if ($freightamt === null) {//不免邮（计算运费）
            $freightamt = $freightFee;
            $totalfreight = $freightamt_actual;
        } else {
            $totalfreight = $freightamt;
            if (!$express->isEmpty()) {
                $express = $express->toArray();
                //免运费用顺丰
                foreach ($express as &$exp) {
                    if (array_get($exp, "name") != "顺丰速运") {
                        $exp = [];
                    }
                }
                $express = array_filter($express);
                $express = array_values($express);
            }
            if (!empty($chooseExpress) && object_get($chooseExpress, "name") != "顺丰速运") {
                $arr = ['status' => false, 'msg' => '快递公司异常'];
                return compact("arr");
            }
        }

//        $totalfreight = sprintf("%.2f", $totalfreight);
        $freightDiscount = bcsub($freightamt_actual, $totalfreight, 4);
        $totalWeight = $this->deliveryService->getWeight($orderDetailData, $freightId);
        $totalWeight = sprintf("%.2f", $totalWeight["billingWeight"]); //计费重量(kg)
        $totaldue = ($priceSum + $totalfreight) - $discountamt - $adjustAmount; //应付 (产品金额 - 折扣金额 + 配送费 - 其他调整)
        //return $totaldue;
//        $totaldue = ($priceSum + $freightamt) - $firsttotalprice - $discountamt; //应付
        $totaldue = sprintf("%.2f", $totaldue);
        $priceSum = sprintf("%.2f", $priceSum);
        $vpsum = sprintf('%.2f', $vpsum);
        //资料套装免邮

        $arr = [
            "status" => true,
            "products" => $products,
            "giftProducts" => $giftProducts,
            "quantitysum" => $quantitysum,
            "priceSum" => $priceSum,
            "pointSum" => $pointSum,
            "vpsum" => $vpsum,
            "freightamt" => $freightamt_actual, //配送费
            "freightDiscount" => $freightDiscount, //配送费(减免)
            "totaldue" => $totaldue,
            "address" => $address,
            "discountamt" => $discountamt,
            "express" => $express,
            "receiptdate" => $receiptdate,
            "orderer" => $orderer,
            "months" => $months,
            "freightId" => $freightId,
            "totalWeight" => $totalWeight,
            "adjustAmount" => $adjustAmount,
            "promotionList" => $promotionList
        ];
        return compact("arr");
    }

    /**
     *手工订单获取运费数据
     */
    public function Getfeimat($data, $platform = "wechat", $type = 1)
    {
        $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //资格证号
        $now = Carbon::now();
        $uid = $this->commonService->getOrderingUserId($platform, $type, $customerId); //订购用户id
        $cid = $uid; //缓存关联id
        $cpid = $this->commonService->getCustomerProfileId($customerId, $platform); //客户id
        $customerid = $this->commonService->getCustomerid($cpid); //客户资格证号
        $cusInfo = $this->commonService->getCustomerInfo($cpid); //客户信息
        $is_active = object_get($cusInfo, "is_active", 0); //是否在册，1在册，0退出
        $created_by = $uid; //创建人
        $orderProvinceId = isset($data["province_id"]) ? $data["province_id"] : 0; //订货人省份id
        $orderProducts = isset($data["products"]) ? $data["products"] : []; //订单产品列表(admin接口)
        $promotion = []; //促销列表
        $timeout = 0; //赠品超时时间
        $totalPrice = 0; //产品价格
        $totalCmsPrice = 0; //计酬产品价格
        $basePrice = 0; //基础价格（产品价格+运费）
        $totalVp = 0; //总VP值
        $firsttotalprice = 0; //首单价格
        $firstTotalVp = 0; //首单VP
        $discountamt = 0; //折扣价格
        $freightamt = null; //运费(B2C)
        $totalfreight = 0; //配送费(保险费 + B2C运费)
        $taxamt = 0; //税费
        $insurance = 0; //保险费
        $adjustAmount = 0; //其他调整应付金额
        $orderDetailData = []; //订单详情
        $orderInventoryData = []; //库存详情
        $orderInventoryGiftData = []; //赠品库存详情
        $freightId = intval($data["freightId"]); //快递公司id
        $addressId = isset($data['addressId']) ? intval($data['addressId']) : 0; //地址id
        $pids = empty($data["pids"]) ? "" : explode(",", $data["pids"]); //购物车选择产品id
        $prom = isset($data["prom"]) ? $data["prom"] : []; //赠品选择列表
        $productIds = []; //购买产品id集合
        $express = DeliveryExpress::select("name", "freightamt", "insurance_rate")->where("id", $freightId)->first(); //快递公司
        $address = CustomerAddress::select("province_id", "city_id", "district_id", "address", "zipcode", "phoneno", "receivername")
            ->where("id", $addressId)
            ->whereRaw("(orderinguser_id=? or customerprofile_id=?)", [$uid, $cpid])
            ->where("isdeleted", 0)
            ->first(); //客户地址

        $orderId = $this->getOrderId($cid, $orderProvinceId, $freightId); //订单编号
        if ($platform == OrderingUser::$platform[2]) {//admin接口调用
            if (empty($orderProducts)) {
                return ['status' => false, 'msg' => "产品为空"];
            }
            foreach ($orderProducts as $key => $orderProduct) {
                if (!isset($orderProduct["id"]))
                    continue;
                $orderProductId = isset($orderProduct["id"]) ? $orderProduct["id"] : 0;
                $productIds[] = $orderProductId;
                $quantity = isset($orderProduct["quantity"]) ? (int)$orderProduct["quantity"] : 0;
                if (!is_numeric($quantity) || $quantity <= 0) {
                    continue;
                }
                $product = $this->commonService->getProduct($orderProductId, $orderProvinceId, $freightId, $platform, $type); //产品
                if (empty($product))
                    continue;
                $stock = $product["stock"]; //产品库存
                if (in_array($data['products'][0]['sku'], ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {
                    $product_sku = $data['products'][0]['sku'];
                } else {
                    if ($quantity > $stock) {
                        return ['status' => false, 'msg' => "产品{$product["sku"]} - {$product["name"]}库存不足"];
                    }
                }

                $orderInventoryData[] = $this->commonService->modifyInventory($orderProductId, $quantity, $orderProvinceId, $freightId, $platform, $type); //待修改库存信息
                $orderInventoryData = array_filter($orderInventoryData);
                $price = $this->commonService->getPrice($orderProductId, $orderProvinceId, $platform, $type);
                $totalPrice += $price["Price"] * $quantity; //产品金额
                if ($price["iscommission"]) {
                    $totalCmsPrice += $price["prePrice"] * $quantity;
                } //计酬产品金额
                $totalVp += $price["vp"] * $quantity; //产品VP
                $totalVp = sprintf('%.2f', $totalVp);
                $taxamt += $price["taxamt"] * $quantity; //产品税费
                $orderDetailData[] = [
                    "orderheader_id" => "",
                    "product_id" => $orderProductId,
                    "quantity" => $quantity,
                    "UnitPrice" => $price["Price"],
                    "vp" => $price["vp"],
                    "Points" => $price["Points"],
                    "taxamt" => $price["taxamt"],
                    "taxrate" => $price["taxrate"],
                    "isdeleted" => 0
                ];
            }
            $created_by = array_get($data, "username", "");
        }
        $promotion = $this->commonService->getPromotion($orderDetailData, $orderProvinceId, $totalPrice, $totalVp, $cpid); //促销列表
        if (empty($prom)) {
            $promotion = null;
        }
        if (!empty($promotion)) {
            foreach ($promotion as $promList) {
                $ptypes = !empty($promList["type"]) ? explode(",", $promList["type"]) : []; //优惠方式
                if (!empty($ptypes)) {
                    foreach ($ptypes as $ptype) {
                        if ($ptype == "lessCash") {//减免金额
                            $discountamt += ($promList["discountamt"] * $promList["apieceNum"]);
                        } else {
                            if ($ptype == "discount") {//折扣
                                $discountamt += $totalPrice * (1 - ($promList["discountrate"] / 10));
                            } else {
                                if ($ptype == "gifts") {//赠品
                                    $gids = !empty($promList["freeproduct_id"]) ? explode(",", $promList["freeproduct_id"]) : []; //产品id列表
                                    $choiceProdNum = 0; //选择赠品数量
                                    if (!empty($gids)) {
                                        $prom_index = -1;
                                        foreach ($prom as $key => $promItems) {
                                            if ($promItems["id"] == $promList["id"]) {
                                                $prom_index = $key;
                                                break;
                                            }
                                        }
                                        $prom_gids = isset($prom[$prom_index]) && !empty($prom[$prom_index]["pids"]) ? explode(",", $prom[$prom_index]["pids"]) : [];
                                        foreach ($gids as $gid) {
                                            if ($promList["freeproduct_qty"] > 0 && $choiceProdNum == $promList["freeproduct_qty"]) {
                                                break;
                                            }
                                            $giftStock = $this->commonService->getStock($gid, $orderProvinceId, $freightId, $platform, $type); //产品库存
                                            $orderNum = array_get($promList, "apieceNum");
                                            if (in_array($gid, $productIds)) {
                                                foreach ($orderDetailData as $list) {
                                                    if ($list["product_id"] == $gid) {
                                                        $orderNum += $list["quantity"];
                                                        break;
                                                    }
                                                }
                                            }
                                            $product = $this->commonService->getProduct($gid, $orderProvinceId, $freightId, $platform, $type);
                                            if (!empty($product) && (!empty($prom) && in_array($gid, $prom_gids)) || empty($prom) && (($promList["freeproduct_qty"] == 0 && $orderNum <= $giftStock) || ($promList["freeproduct_qty"] > 0 && $choiceProdNum < $promList["freeproduct_qty"] && $orderNum <= $giftStock))
                                            ) {//有可扣库存
                                                $base = ProductPricebase::select("taxamt")
                                                    ->where("product_id", $gid)
                                                    ->where("effectivedate", "<=", $now)
                                                    ->where("expireddate", ">=", $now)
                                                    ->where("isdeleted", 0)
                                                    ->first(); //基本价格
                                                $taxamt += $base["taxamt"]; //产品税费
                                                $giftData = [
                                                    "orderheader_id" => "",
                                                    "product_id" => $gid,
                                                    "quantity" => array_get($promList, "apieceNum"),
                                                    "UnitPrice" => 0,
                                                    "vp" => 0,
                                                    "Points" => 0,
                                                    "isdeleted" => 0,
                                                    "taxamt" => 0,
                                                    "taxrate" => 0,
                                                ]; //赠品
                                                $orderDetailData[] = $giftData;
                                                $orderInventoryGiftData[] = $giftData;
                                                $choiceProdNum++;
                                            }
                                        }
                                    }
                                } else {
                                    if ($ptype == "freeShipping" && ($promList["isfreepostage"] == 1)) {//免邮
                                        $withoutregionId = !empty($promList["withoutregion_id"]) ? explode(",", $promList["withoutregion_id"]) : []; //不免邮地区
                                        if (!in_array($orderProvinceId, $withoutregionId)) {
                                            $freightamt = 0;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $basePrice = $totalPrice + $freightamt;
        $freightFee = $this->deliveryService->getExpressFee($orderDetailData, $orderProvinceId, $freightId);
        $insurance = ($totalPrice * $express["insurance_rate"]); //保险费 (产品总金额 * 保险费费率)
        $insurance = sprintf("%.2f", $insurance);
        $freightamt_actual = $insurance + $freightFee; //配送费 (保险费 + B2C运费)
        if ($freightamt === null) {//不免邮（计算运费）
            $freightamt = $freightFee;
            $totalfreight = $freightamt_actual;
        } else {
            $totalfreight = $freightamt;
        }
        $freightamt = sprintf("%.2f", $freightamt);
        $totalfreight = sprintf("%.2f", $totalfreight);
        if (!empty($freightamt)) {
            return ['status' => 1, "msg" => $freightamt];
        } else {
            return ['status' => 0, "msg" => "运费获取失败"];
        }

    }

    /**
     * 提交订单
     */
    public function postOrderData($data, $platform = "wechat", $type = 1)
    {
        try {
            $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //资格证号
            $now = Carbon::now();
            $uid = $this->commonService->getOrderingUserId($platform, $type, $customerId); //订购用户id
            $cid = $uid; //缓存关联id
            $cpid = $this->commonService->getCustomerProfileId($customerId, $platform); //客户id
            $customerid = $this->commonService->getCustomerid($cpid); //客户资格证号
            $cusInfo = $this->commonService->getCustomerInfo($cpid); //客户信息
            $is_active = object_get($cusInfo, "is_active", 0); //是否在册，1在册，0退出
            if ($platform == OrderingUser::$platform[2] && (empty($customerId) || empty($cusInfo) || $now > $cusInfo["renewdate"] || ($is_active == 0) || $this->blacklistService->existInBlack($cpid))) {
                return ['status' => false, 'msg' => "资格证号非法"];
            }
            $created_by = $uid; //创建人
            $orderProvinceId = isset($data["province_id"]) ? $data["province_id"] : 0; //订货人省份id
            $cacheKey = $this->commonService->getOrderCartsCacheKey($platform, $uid); //购物车缓存键
            $addrCacheKey = $this->commonService->getOrderAddrCacheKey($platform, $uid); //订购地址缓存键
            $carts = Cache::get($cacheKey);
            // $nums = empty($data["nums"]) ? 0 : intval($data["nums"]); //立即购买才有数量
            $nums = 0; //立即购买才有数量
            if (!empty($data["nums"])) {
                $validator = Validator::make($data, [
                    'nums' => [
                        'required',
                        'regex:/^([0-9]|[1-9][0-9]*)$/'
                    ],
                ]);
                if ($validator->fails()) {
                    $arr = ['status' => false, 'msg' => '参数非法'];
                    return compact("arr");
                }
                $nums = intval($data["nums"]);
            }
            $month = empty($data["month"]) ? "" : $data["month"]; //订单月
            $remark = array_get($data, "remark", ""); //备注
            $products = $carts["carts"]; //购物车列表
            $orderProducts = isset($data["products"]) ? $data["products"] : []; //订单产品列表(admin接口)
            $promotion = []; //促销列表
            $timeoutList = []; //赠品超时时间列表
            $timeout = 0; //赠品超时时间
            $totalPrice = 0; //产品价格
            $totalPoint = 0; //产品消费积分
            $totalCmsPrice = 0; //计酬产品价格
            $basePrice = 0; //基础价格（产品价格+运费）
            $totalVp = 0; //总VP值
            $firsttotalprice = 0; //首单价格
            $firstTotalVp = 0; //首单VP
            $discountamt = 0; //折扣价格
            $freightamt = null; //运费(B2C)
            $totalfreight = 0; //配送费(保险费 + B2C运费)
            $taxamt = 0; //税费
            $insurance = 0; //保险费
            $adjustAmount = 0; //其他调整应付金额
            $orderDetailData = []; //订单详情
            $orderSeriesDetailData = [];//订单套装明细
            $orderInventoryData = []; //库存详情
            $orderInventoryGiftData = []; //赠品库存详情
            $freightId = intval($data["freightId"]); //快递公司id
            $addressId = isset($data['addressId']) ? intval($data['addressId']) : 0; //地址id
            $pids = empty($data["pids"]) ? "" : explode(",", $data["pids"]); //购物车选择产品id
            $prom = isset($data["prom"]) ? $data["prom"] : []; //赠品选择列表
            $productIds = []; //购买产品id集合
            $express = DeliveryExpress::select("name", "freightamt", "insurance_rate")->where("id", $freightId)->first(); //快递公司
            $address = CustomerAddress::select("province_id", "city_id", "district_id", "address", "zipcode", "phoneno", "receivername")
                ->where("id", $addressId)
                ->whereRaw("(orderinguser_id=? or customerprofile_id=?)", [$uid, $cpid])
                ->where("isdeleted", 0)
                ->first(); //客户地址
            $exist = OrderingOrderheader::select("id")->where("customerid", $customerid)->where("isdeleted", 0)->where("status", 0)->first(); //是否存在未支付订单
            if (!empty($exist)) {
                return ['status' => false, 'msg' => "存在未支付订单"];
            }
            if (!empty($address)) {
                $orderProvinceId = $address["province_id"];
            }
            if ($platform != OrderingUser::$platform[2] && (empty($addressId) || empty($address))) {
                return ['status' => false, 'msg' => "请选择收货地址"];
            }

            if (empty($freightId)) {
                return ['status' => false, 'msg' => "请选择快递公司"];
            }
            $orderId = $this->getOrderId($cid, $orderProvinceId, $freightId); //订单编号
            $exist = OrderingOrderheader::select("id")->where("orderid", $orderId)->where("isdeleted", 0)->first(); //是否订单重复
            if (!empty($exist)) {
                return ['status' => false, 'msg' => "订单重复提交"];
            }
            if ($carts["cid"] == $cid && empty($nums)) {//购物车购买
                if (!empty($products) && !empty($pids)) {//计算购物车总价
                    foreach ($products as $key => $product) {
                        if (in_array($key, $pids)) {
                            $quantity = $product["quantity"];
                            if (!is_numeric($quantity) || $quantity <= 0) {
                                continue;
                            }
                            $prod = $this->commonService->getProduct($key, $orderProvinceId, $freightId, $platform, $type); //产品
                            $stock = $prod["stock"]; //产品库存
                            $is_series = $prod["is_series"];//是否套装
                            if ($quantity > $stock) {
                                return ['status' => false, 'msg' => "产品{$prod["sku"]} - {$prod["name"]}库存不足"];
                            }
                            $productIds[] = $key;
                            $orderInventoryData[] = $this->commonService->modifyInventory($key, $quantity, $orderProvinceId, $freightId, $platform, $type); //待修改库存信息
                            $orderInventoryData = array_filter($orderInventoryData);
                            $price = $this->commonService->getPrice($key, $orderProvinceId, $platform, $type);
                            $price_base = [
                                'Price' => object_get($price, 'Price'),
                                'Points' => object_get($price, 'Points'),
                                'vp' => object_get($price, 'vp')
                            ];
                            if (object_get($price, 'Price')) {
                                $validator = Validator::make($price_base, [
                                    'Price' => [
                                        'required',
                                        'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                                    ],
                                    'vp' => [
                                        'required',
                                        'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                                    ],
                                    'Points' => [
                                        'required',
                                        'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                                    ],
                                ]);
                                if ($validator->fails()) {
                                    return ['status' => false, 'msg' => '参数非法'];
                                }
                            }
                            $totalPrice += $price["Price"] * $quantity; //产品金额
                            if ($price["iscommission"]) {
                                $totalCmsPrice += $price["prePrice"] * $quantity; //SUM(订单中计酬产品的税前金额)
                            } //计酬产品金额
                            $totalVp += $price["vp"] * $quantity; //产品VP
                            $totalPoint += $price["Points"] * $quantity; //产品消费积分
                            $totalPoint = sprintf('%.2f', $totalPoint);
                            $totalVp = sprintf('%.2f', $totalVp);
                            $taxamt += $price["taxamt"] * $quantity; //产品税费

                            $app_point = $this->commonService->getAppPoints($customerid, $platform);
                            if ($app_point['points'] - $totalPoint < 0) {
                                return ['status' => false, 'msg' => "电子券不足！"];
                            }
                            $price_diff = 0;
                            if($is_series==1){
                                $seriesDetail =  $this->getSeriesDetail($key,$quantity);
                                $seriesDetailData = array_get($seriesDetail,"seriesDetailData");
                                $seriesPriceSum = array_get($seriesDetail,"priceSum");
                                $price_diff =  bcsub($price["Price"]*$quantity,$seriesPriceSum,4);
                                $orderSeriesDetailData = array_merge($orderSeriesDetailData,$seriesDetailData);
                            }
                            $orderDetailData[] = [
                                "orderheader_id" => "",
                                "product_id" => $key,
                                "quantity" => $quantity,
                                "UnitPrice" => $price["Price"],
                                "price_diff" => $price_diff,
                                "vp" => $price["vp"],
                                "Points" => $price["Points"],
                                "taxamt" => $price["taxamt"],
                                "taxrate" => $price["taxrate"],
                                "isdeleted" => 0
                            ];
                            unset($products["$key"]);
                        }
                    }
                } else {
                    return ['status' => false, 'msg' => "购物车为空"];
                }
            } elseif (!empty($nums) && !empty($pids)) {//立即购买
                if (!is_numeric($nums) || $nums <= 0) {
                    return ['status' => false, 'msg' => "参数非法"];
                }
                $pid = $pids[0];
                $price = $this->commonService->getPrice($pid, $orderProvinceId, $platform, $type);
                $price_base = [
                    'Price' => object_get($price, 'Price'),
                    'Points' => object_get($price, 'Points'),
                    'vp' => object_get($price, 'vp')
                ];
                if (object_get($price, 'Price')) {
                    $validator = Validator::make($price_base, [
                        'Price' => [
                            'required',
                            'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                        ],
                        'vp' => [
                            'required',
                            'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                        ],
                        'Points' => [
                            'required',
                            'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                        ],
                    ]);
                    if ($validator->fails()) {
                        return ['status' => false, 'msg' => '参数非法'];
                    }
                }
                $totalPrice += $price["Price"] * $nums; //产品金额
                if ($price["iscommission"]) {
                    $totalCmsPrice += $price["prePrice"] * $nums;
                } //计酬产品金额
                $totalVp += $price["vp"] * $nums; //产品VP
                $totalPoint += $price["Points"] * $nums; //产品VP
                $totalPoint = sprintf('%.2f', $totalPoint);
                $totalVp = sprintf('%.2f', $totalVp);
                $taxamt += $price["taxamt"] * $nums; //产品税费

                $app_point = $this->commonService->getAppPoints($customerid, $platform);
                if ($app_point['points'] - $totalPoint < 0) {
                    return ['status' => false, 'msg' => "电子券不足！"];
                }
                $prod = $this->commonService->getProduct($pid, $orderProvinceId, $freightId, $platform, $type); //产品

                $stock = $prod["stock"]; //产品库存
                $is_series = $prod["is_series"];//是否套装
                if ($nums > $stock) {
                    return ['status' => false, 'msg' => "产品{$prod["sku"]} - {$prod["name"]}库存不足"];
                }
                $productIds[] = $pid;
                $orderInventoryData[] = $this->commonService->modifyInventory($pid, $nums, $orderProvinceId, $freightId, $platform, $type); //待修改库存信息
                $orderInventoryData = array_filter($orderInventoryData);
                $price_diff = 0;
                if($is_series==1){
                    $seriesDetail =  $this->getSeriesDetail($pid,$nums);
                    $seriesDetailData = array_get($seriesDetail,"seriesDetailData");
                    $seriesPriceSum = array_get($seriesDetail,"priceSum");
                    $price_diff =  bcsub($price["Price"]*$nums,$seriesPriceSum,4);
                    $orderSeriesDetailData = array_merge($orderSeriesDetailData,$seriesDetailData);
                }
                $orderDetailData[] = [
                    "orderheader_id" => "",
                    "product_id" => $pid,
                    "quantity" => $nums,
                    "UnitPrice" => $price["Price"],
                    "price_diff" => $price_diff,
                    "vp" => $price["vp"],
                    "Points" => $price["Points"],
                    "taxamt" => $price["taxamt"],
                    "taxrate" => $price["taxrate"],
                    "isdeleted" => 0
                ];
            } else if ($platform == OrderingUser::$platform[2]) {//admin接口调用
                if (empty($orderProducts)) {
                    return ['status' => false, 'msg' => "产品为空"];
                }
                foreach ($orderProducts as $key => $orderProduct) {
                    if (!isset($orderProduct["id"]))
                        continue;
                    $orderProductId = isset($orderProduct["id"]) ? $orderProduct["id"] : 0;
                    $productIds[] = $orderProductId;
                    $quantity = isset($orderProduct["quantity"]) ? (int)$orderProduct["quantity"] : 0;
                    if (!is_numeric($quantity) || $quantity <= 0) {
                        continue;
                    }
                    $product = $this->commonService->getProduct($orderProductId, $orderProvinceId, $freightId, $platform, $type); //产品
                    if (empty($product))
                        continue;
                    $stock = $product["stock"]; //产品库存
                    $is_series = $product["is_series"];//是否套装
                    if (in_array($data['products'][0]['sku'], ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {
                        $product_sku = $data['products'][0]['sku'];
                    } else {
                        if ($quantity > $stock) {
                            return ['status' => false, 'msg' => "产品{$product["sku"]} - {$product["name"]}库存不足"];
                        }
                    }

                    $orderInventoryData[] = $this->commonService->modifyInventory($orderProductId, $quantity, $orderProvinceId, $freightId, $platform, $type); //待修改库存信息
                    $orderInventoryData = array_filter($orderInventoryData);
                    $price = $this->commonService->getPrice($orderProductId, $orderProvinceId, $platform, $type);
                    $totalPrice += $price["Price"] * $quantity; //产品金额
                    if ($price["iscommission"]) {
                        $totalCmsPrice += $price["prePrice"] * $quantity;
                    } //计酬产品金额
                    $totalVp += $price["vp"] * $quantity; //产品VP
                    $totalPoint += $price["Points"] * $quantity; //产品VP
                    $totalPoint = sprintf('%.2f', $totalPoint);
                    $totalVp = sprintf('%.2f', $totalVp);
                    $taxamt += $price["taxamt"] * $quantity; //产品税费
                    $app_point = $this->commonService->getAppPoints($customerid, $platform);
                    if ($app_point['points'] - $totalPoint < 0) {
                        return ['status' => false, 'msg' => "电子券不足！"];
                    }
                    $price_diff = 0;
                    if($is_series==1){
                        $seriesDetail =  $this->getSeriesDetail($orderProductId,$quantity);
                        $seriesDetailData = array_get($seriesDetail,"seriesDetailData");
                        $seriesPriceSum = array_get($seriesDetail,"priceSum");
                        $price_diff =  bcsub($price["Price"]*$quantity,$seriesPriceSum,4);
                        $orderSeriesDetailData = array_merge($orderSeriesDetailData,$seriesDetailData);
                    }
                    $orderDetailData[] = [
                        "orderheader_id" => "",
                        "product_id" => $orderProductId,
                        "quantity" => $quantity,
                        "UnitPrice" => $price["Price"],
                        "price_diff" => $price_diff,
                        "vp" => $price["vp"],
                        "Points" => $price["Points"],
                        "taxamt" => $price["taxamt"],
                        "taxrate" => $price["taxrate"],
                        "isdeleted" => 0
                    ];
                }
                $created_by = array_get($data, "username", "");
            } else {
                return ['status' => false, 'msg' => "提交订单失败"];
            }

            if (empty($orderDetailData)) {
                return ['status' => false, 'msg' => "请选择一个商品"];
            }
            //手工订单参与促销
//            if ($platform != OrderingUser::$platform[2]) {
            $promotion = $this->commonService->getPromotion($orderDetailData, $orderProvinceId, $totalPrice, $totalVp, $cpid); //促销列表
//            }
            if ($platform == OrderingUser::$platform[2] && in_array($data['products'][0]['sku'], ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {//资料套装不参加促销
                $promotion = null;
            }
            if (!empty($promotion)) {
                foreach ($promotion as $promList) {
                    $ptypes = !empty($promList["type"]) ? explode(",", $promList["type"]) : []; //优惠方式
                    if (!empty($ptypes)) {
                        foreach ($ptypes as $ptype) {
                            if ($ptype == "lessCash") {//减免金额
                                $discountamt += ($promList["discountamt"] * $promList["apieceNum"]);
                            } else {
                                if ($ptype == "discount") {//折扣
                                    $discountamt += $totalPrice * (1 - ($promList["discountrate"] / 10));
                                } else {
                                    if ($ptype == "gifts") {//赠品
                                        $gids = !empty($promList["freeproduct_id"]) ? explode(",", $promList["freeproduct_id"]) : []; //产品id列表
                                        $choiceProdNum = 0; //选择赠品数量
                                        if (!empty($gids)) {
                                            $prom_index = -1;
                                            foreach ($prom as $key => $promItems) {
                                                if ($promItems["id"] == $promList["id"]) {
                                                    $prom_index = $key;
                                                    break;
                                                }
                                            }
                                            $prom_gids = isset($prom[$prom_index]) && !empty($prom[$prom_index]["pids"]) ? explode(",", $prom[$prom_index]["pids"]) : []; //选择赠品id列表
//                                    if (empty($prom)) {
//                                        $prom_gids = array_slice($gids, 0, ($promList["freeproduct_qty"] == 0 ? null : $promList["freeproduct_qty"]));
//                                    }
                                            foreach ($gids as $gid) {
                                                if ($promList["freeproduct_qty"] > 0 && $choiceProdNum == $promList["freeproduct_qty"]) {
                                                    break;
                                                }
                                                $giftStock = $this->commonService->getStock($gid, $orderProvinceId, $freightId, $platform, $type); //产品库存
                                                $orderNum = array_get($promList, "apieceNum");
                                                if (in_array($gid, $productIds)) {
                                                    foreach ($orderDetailData as $list) {
                                                        if ($list["product_id"] == $gid) {
                                                            $orderNum += $list["quantity"];
                                                            break;
                                                        }
                                                    }
                                                }
                                                $product = $this->commonService->getProduct($gid, $orderProvinceId, $freightId, $platform, $type);
                                                if (!empty($prom) && in_array($gid, $prom_gids) && $orderNum > $giftStock
                                                ) {
                                                    return [
                                                        'status' => false,
                                                        'msg' => "因`{$product["sku"]} - {$product["name"]}`享受促销，赠品`{$product["sku"]} - {$product["name"]}`库存不足，请返回购物车重新选择。"
                                                    ];
                                                }
                                                if (!empty($product) && (!empty($prom) && in_array($gid, $prom_gids)) || empty($prom) && (($promList["freeproduct_qty"] == 0 && $orderNum <= $giftStock) || ($promList["freeproduct_qty"] > 0 && $choiceProdNum < $promList["freeproduct_qty"] && $orderNum <= $giftStock))
                                                ) {//有可扣库存
                                                    $base = ProductPricebase::select("taxamt")
                                                        ->where("product_id", $gid)
                                                        ->where("effectivedate", "<=", $now)
                                                        ->where("expireddate", ">=", $now)
                                                        ->where("isdeleted", 0)
                                                        ->first(); //基本价格
                                                    //$taxamt += $base["taxamt"]; //产品税费
                                                    $giftData = [
                                                        "orderheader_id" => "",
                                                        "product_id" => $gid,
                                                        "quantity" => array_get($promList, "apieceNum"),
                                                        "UnitPrice" => 0,
                                                        "price_diff" => 0,
                                                        "vp" => 0,
                                                        "Points" => 0,
                                                        "isdeleted" => 0,
                                                        "taxamt" => 0,
                                                        "taxrate" => 0,
                                                    ]; //赠品
                                                    $orderDetailData[] = $giftData;
                                                    $timeoutList[] = array_get($promList, "timeout", 0);
                                                    $orderInventoryGiftData[] = $giftData;
                                                    $choiceProdNum++;
                                                }
                                            }
                                        }
                                    } else {
                                        if ($ptype == "freeShipping" && ($promList["isfreepostage"] == 1)) {//免邮
                                            $withoutregionId = !empty($promList["withoutregion_id"]) ? explode(",", $promList["withoutregion_id"]) : []; //不免邮地区
                                            if (!in_array($orderProvinceId, $withoutregionId)) {
                                                $freightamt = 0;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if ($platform == OrderingUser::$platform[2] && in_array($data['products'][0]['sku'], ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {//资料套装默认免邮
                $freightamt = 0;
            }
            if (count($timeoutList)) {
                $timeout = max($timeoutList);
            }
            $basePrice = $totalPrice + $freightamt;
            if (isset($data["allowance"]) && $data["allowance"] == 1) {//启用积分抵扣
                $discount = CustomerPoints::selectRaw("sum(DiscountAmt) as DiscountAmt")
                    ->where("orderinguser_id", $uid)
                    ->where("effectivedate", "<=", $now)
                    ->where("expireddate", ">=", $now)
                    ->where("status", 1)
                    ->where("isdeleted", 0)
                    ->first();
                if (!empty($discount["discountamt"])) {
                    $discountamt = ($discount["discountamt"] >= $basePrice) ? $basePrice : $discount["discountamt"];
                }
            }
            $freightFee = $this->deliveryService->getExpressFee($orderDetailData, $orderProvinceId, $freightId);
            $insurance = ($totalPrice * $express["insurance_rate"]); //保险费 (产品总金额 * 保险费费率)
            $insurance = sprintf("%.2f", $insurance);
            $freightamt_actual = $insurance + $freightFee; //配送费 (保险费 + B2C运费)
            if ($freightamt === null) {//不免邮（计算运费）
                $freightamt = $freightFee;
                $totalfreight = $freightamt_actual;
            } else {
                $totalfreight = $freightamt;
                // 免邮用顺丰
                if (object_get($express, "name") != "顺丰速运") {
                    return ['status' => false, 'msg' => "快递公司异常"];
                }
            }
            $proWeight = $this->deliveryService->getWeight($orderDetailData, $freightId); //产品重量
            $discountamt += $firsttotalprice; //把首单价格加入折扣

            $totaldue = ($totalPrice + $totalfreight) - $discountamt - $adjustAmount; //应付 (产品金额 - 折扣金额 + 配送费 - 其他调整)
            if ($totaldue <= 0) {
                return ['status' => false, 'msg' => "应付金额非法"];
            }
            $ordermonth = in_array($month, $this->commonService->getMonths()) ? $month : date("Ym");
            if ($platform == OrderingUser::$platform[2]) {
                $ordermonth = empty($month) ? data("Ym") : $month; //订单月
            }

            $totalPrice = sprintf("%.2f", $totalPrice);
            $taxamt = sprintf("%.2f", $taxamt);
            $discountamt = sprintf("%.2f", $discountamt);
            $freightamt = sprintf("%.2f", $freightamt);
            $totaldue = sprintf("%.2f", $totaldue);
            $insurance = sprintf("%.2f", $insurance);
            $totalfreight = sprintf("%.2f", $totalfreight);
            $totalVp = sprintf('%.2f', $totalVp);
            $orderHeaderData = [
                "orderinguser_id" => $uid,
                "orderid" => $orderId,
                "productamt" => $totalPrice, //产品总额
                "commission_productamt" => $totalCmsPrice, //产品总额
                "taxamt" => $taxamt, //税费
                "vp" => $totalVp, //产品vp
                "use_points" => $totalPoint, //产品消耗积分
                "discountamt" => $discountamt, //折扣总额
                "freightamt" => $freightamt, //运费
                "freightamt_actual" => $freightamt_actual, //实际运费
                "totaldue" => $totaldue, //应付
                "totalpaid" => 0, //实付
                "adjust_amount" => $adjustAmount, //其他调整
                "insurance" => $insurance, //保险费
                "total_freight" => $totalfreight, //配送费
                "status" => 0,
                "ordermonth" => $ordermonth,
                "createtime" => $now,
                "created_at" => $now,
                "created_by" => $created_by,
                "deliverytype" => 0, //配送方式
                "customerid" => $customerid, //客户资格证号
                "remark" => $remark,
            ];
            if ($timeout > 0) {
                $orderHeaderData["deadline"] = Carbon::now()->addMinutes($timeout);
            }
            $orderPackage = [
                "orderheader_id" => "",
                "recomm_one" => $proWeight["recommendCasketNum"], //推荐1号箱
                "recomm_two" => $proWeight["recommendMidchestNum"],
                "recomm_three" => $proWeight["recommendTrunkNum"],
                "recomm_four" => $proWeight["recommendBoxFourNum"],
                // "recomm_five" => $proWeight["recommendBoxFiveNum"],
            ]; //订单箱型
            $warehouseId = $this->deliveryService->getWarehouseId($orderProvinceId, $freightId); //库位id
            if ($platform != OrderingUser::$platform[2]) {
                $orderAddrData = [
                    "orderheader_id" => "",
                    "province_id" => $address["province_id"],
                    "city_id" => $address["city_id"],
                    "district_id" => $address["district_id"],
                    "receivername" => $address["receivername"],
                    "address" => $address["address"],
                    "zipcode" => $address["zipcode"],
                    "phoneno" => $address["phoneno"],
                    "deliveryschedule" => $data["receiptdate"], //配送时间
                    "warehouse_id" => $warehouseId, //库位id
                    "express_id" => $freightId, //快递公司
                    "charge_weight" => $proWeight["billingWeight"], //计费重量(kg)
                    "weight_cargo" => $proWeight["enwrapWeight"], //包裹实重(kg)
                    "volume_cargo" => $proWeight["enwrapGlobalWeight"], //包裹泡重(kg)
                    "volume_sum" => $proWeight["protVolumeSum"], //产品总体积(mm^3)
                    "weight_sum" => $proWeight["protWeightSum"], //产品净重(kg)
                ]; //客户地址
            } else {
                $orderAddrData = [
                    "orderheader_id" => "",
                    "province_id" => $orderProvinceId,
                    "city_id" => intval($data["city_id"]),
                    "district_id" => intval($data["district_id"]),
                    "receivername" => $data["receivername"],
                    "address" => $data["address"],
                    "zipcode" => isset($data["zipcode"]) ? $data["zipcode"] : "",
                    "phoneno" => $data["phoneno"],
                    "deliveryschedule" => $data["receiptdate"], //配送时间
                    "warehouse_id" => $warehouseId, //库位id
                    "express_id" => $freightId, //快递公司
                    "charge_weight" => $proWeight["billingWeight"], //计费重量(kg)
                    "weight_cargo" => $proWeight["enwrapWeight"], //包裹实重(kg)
                    "volume_cargo" => $proWeight["enwrapGlobalWeight"], //包裹泡重(kg)
                    "volume_sum" => $proWeight["protVolumeSum"], //产品总体积(mm^3)
                    "weight_sum" => $proWeight["protWeightSum"], //产品净重(kg)
                ]; //客户地址
            }
            DB::transaction(function () use (
                $orderProvinceId,
                $orderId,
                $customerid,
                $totalPoint,
                $freightId,
                $uid,
                $orderInventoryData,
                $orderInventoryGiftData,
                $data,
                $nums,
                $orderHeaderData,
                $orderDetailData,
                $orderSeriesDetailData,
                $cacheKey,
                $addrCacheKey,
                $orderAddrData,
                &$orderid,
                $carts,
                $products,
                $cid,
                $cpid,
                $now,
                $type,
                $express,
                $platform,
                $orderPackage
            ) {
                $headerRst = OrderingOrderheader::insertGetId($orderHeaderData); //新增订单数据
                $orderData = [];
                $orderDetailData = array_map(function ($orderData) use ($headerRst) {
                    $newData = $orderData;
                    $newData["orderheader_id"] = $headerRst;
                    return $newData;
                }, $orderDetailData); //回调添加订单id
                $detailRst = OrderingOrderdetail::insert($orderDetailData); //新增订单明细
                if(!empty($orderSeriesDetailData)){//套装明细
                    $orderSeriesData = [];
                    $orderSeriesDetailData = array_map(function ($orderSeriesData) use ($headerRst) {
                        $newData = $orderSeriesData;
                        $newData["orderheader_id"] = $headerRst;
                        return $newData;
                    }, $orderSeriesDetailData); //回调添加订单id
                    $seriesdetailRst = OrderingSeriesdetail::insert($orderSeriesDetailData); //新增套装明细
                }
//$expressRst = OrderingDelivery::insertGetId(["name" => $express["name"], "freightamt" => $express["freightamt"]]); //新增订单快递信息
                $orderAddrData["orderheader_id"] = $headerRst;
                $orderPackage["orderheader_id"] = $headerRst;
                $deliveryRst = OrderingDelivery::insertGetId($orderAddrData); //新增订单配送
                $packageRst = OrderingPackage::insertGetId($orderPackage); //新增订单箱形
                if (!empty($firstorder) && $headerRst) {//首单有数据，更新首单表
                    $firstRst = OrderingFirstorder::where("id", $firstorder["id"])->update(["firstorderheader_id" => $headerRst]); //首单表更新
                }
                if (isset($data["allowance"]) && $data["allowance"] == 1) {//启用积分抵扣,更新积分表为已使用
                    $couponRst = CustomerPoints::where("wechat_id", $cid)
                        ->where("orderinguser_id", $uid)
                        ->where("effectivedate", "<=", $now)
                        ->where("expireddate", ">=", $now)
                        ->where("status", 1)
                        ->where("isdeleted", 0)
                        ->update(["status" => 0, "orderheader_id" => $headerRst]);
                }
                if ($platform == OrderingUser::$platform[2] && in_array($data['products'][0]['sku'], ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {//资料套装默认免邮
                    $orderInventoryData = null;
                }
                if (!empty($orderInventoryData)) {//更新产品库存，增加日志记录
                    foreach ($orderInventoryData as $orderInventory) {
                        $productNum = $orderInventory["product_num"];
                        $inventoryData = [
                            "updated_at" => $now,
                            "sold_quantity" => DB::raw("sold_quantity-{$productNum}"),
                            "reserve_quantity" => DB::raw("reserve_quantity+{$productNum}"),
                        ]; //修改库存信息
                        $query = ProductInventory::whereId($orderInventory["id"])
                            ->where("sold_quantity", ">=", $productNum)
                            ->update($inventoryData);
                        if (!$query) {
                            throw new \Exception("产品id：{$orderInventory["product_id"]},数量：{$productNum},库存不足");
                        }
                        $inventorylogData = [
                            "product_id" => $orderInventory["product_id"],
                            "warehouse_id" => $orderInventory["inventory"]["warehouse_id"],
                            "sold_quantity" => $orderInventory["inventory"]["sold_quantity"],
                            "reserve_quantity" => $orderInventory["inventory"]["reserve_quantity"],
                            "changed_sold_qty" => -$orderInventory["product_num"],
                            "changed_reserve_qty" => $orderInventory["product_num"],
                            "changed_by" => "{$platform}提交订单修改库存",
                            "orderheader_id" => $headerRst,
                            "created_at" => $now,
                            "updated_at" => $now,
                            "isdeleted" => 0
                        ]; //库存修改日志记录
                        $inventorylogRst = ProductInventorylog::insertGetId($inventorylogData); //新增库存修改日志记录
                    }
                    foreach ($orderInventoryGiftData as $inventoryGiftData) {
                        $orderInventory = $this->commonService->modifyInventory($inventoryGiftData["product_id"], $inventoryGiftData["quantity"], $orderProvinceId, $freightId); //待修改库存信息
                        if (!empty($orderInventory)) {
                            $productNum = $orderInventory["product_num"];
                            $inventoryData = [
                                "updated_at" => $now,
                                "sold_quantity" => DB::raw("sold_quantity-{$productNum}"),
                                "reserve_quantity" => DB::raw("reserve_quantity+{$productNum}"),
                            ]; //修改库存信息
                            $query = ProductInventory::whereId($orderInventory["id"])
                                ->where("sold_quantity", ">=", $productNum)
                                ->update($inventoryData);
                            if (!$query) {
                                throw new \Exception("产品id：{$orderInventory["product_id"]},数量：{$productNum},库存不足");
                            }
                            $inventorylogData = [
                                "product_id" => $orderInventory["product_id"],
                                "warehouse_id" => $orderInventory["inventory"]["warehouse_id"],
                                "sold_quantity" => $orderInventory["inventory"]["sold_quantity"],
                                "reserve_quantity" => $orderInventory["inventory"]["reserve_quantity"],
                                "changed_sold_qty" => -$orderInventory["product_num"],
                                "changed_reserve_qty" => $orderInventory["product_num"],
                                "changed_by" => "{$platform}提交订单(促销赠品)修改库存",
                                "orderheader_id" => $headerRst,
                                "created_at" => $now,
                                "updated_at" => $now,
                                "isdeleted" => 0
                            ]; //库存修改日志记录
                            $inventorylogRst = ProductInventorylog::insertGetId($inventorylogData); //新增库存修改日志记录
                        }
                    }
                }
                $orderid = $headerRst;

                if ($totalPoint > 0) {
                    DB::select("call points_modify(?,?,?,?,?,?,?,?,@rst)", [$customerid, $orderId, 0, -$totalPoint, 2, '', $orderId, $customerid]);
                    $result = DB::select("SELECT @rst");
                    $rst = $result[0]->{'@rst'};
                    if ($rst == 0) {
                        Log::error('导入失败原因：', ["存储过程积分抵扣失败"]);
                        throw new \Exception("抵扣积分失败！");
                    }
                }
                if ($platform != OrderingUser::$platform[2]) {
                    if (empty($nums)) {//修改购物车
                        if (empty($products)) {
                            Cache::forget($cacheKey); //清空购物车
                        } else {
                            $carts["carts"] = $products;
                            $expiresAt = Carbon::now()->addMinutes(1440); //保存一天
                            Cache::put($cacheKey, $carts, $expiresAt);
                        }
                    }
                    Cache::forget($addrCacheKey); //清空订单地址缓存
                }
            });
        } catch (\Exception $exc) {
            return ['status' => false, 'msg' => $exc->getLine() . $exc->getFile() . $exc->getMessage()];
            Log::error("订单处理异常", ["文件:" . $exc->getFile(), "行号:" . $exc->getLine(), "异常信息:" . $exc->getMessage()]);
            return ['status' => false, 'msg' => "订单处理异常"];
        }
        return ['status' => true, 'msg' => "提交订单成功", 'orderid' => $orderId];
    }

    /**
     * 获取套装产品详情
     * @param  $pid 套装产品id ,$nums 套装产品数量 ,$date
     */
    public function getSeriesDetail($pid ,$nums ,$date=""){
        $result = [];
        $seriesDetailData = [];
        $price_sum = 0;
        if(!empty($pid)){
            $seriesdetail = ProductSeriesDetail::select("series_product_id","quantity")
                ->where("series_id", $pid)
                ->where("isdeleted", 0)
                ->get();
            if (!$seriesdetail->isEmpty()) {
                $now = $date?:Carbon::now();
                foreach ($seriesdetail as $key => $detail) {
                    $pids = object_get($detail,"series_product_id"); //产品id
                    $quantity = object_get($detail,"quantity");
                    $data = ProductPricebase::select("Price", "vp", "Points", "taxamt", "taxrate", "iscommission")
                        ->where("product_id", $pids)
                        ->where("effectivedate", "<=", $now)
                        ->where("expireddate", ">=", $now)
                        ->where("isdeleted", 0)
                        ->orderBy("id", "desc")
                        ->first(); //基本价格
                    $unitPrice = object_get($data,"Price");
                    $vp = object_get($data,"vp");
                    $points = object_get($data,"Points");
                    $taxamt = object_get($data,"taxamt");
                    $taxrate = object_get($data,"taxrate");
                    $seriesDetailData[] = [
                        "orderheader_id" => "",
                        "series_id" => $pid,
                        "series_product_id" => $pids,
                        "quantity" => $quantity,
                        "UnitPrice" => $unitPrice,
                        "vp" => $vp,
                        "Points" => $points,
                        "taxamt" => $taxamt,
                        "taxrate" => $taxrate,
                        "isdeleted" => 0
                    ];
                    $price_sum +=  $unitPrice*$quantity;
                }
                $result["seriesDetailData"] = $seriesDetailData;
                $result["priceSum"] = ($price_sum*$nums);
            }

        }
        return $result;
    }

    /**
     * 提交订单并付款
     */
    public function postAdminOrderData($data, $platform = "wechat", $type = 1)
    {
        try {
            $result = $this->postOrderData($data, $platform, $type); //提交订单
            $now = Carbon::now();
            if ($result["status"]) {//下单成功
                $orderId = $result["orderid"];
                $money = sprintf("%.4f", $data["actualpay"]); //实付款
                $payment = $data["payment"]; //付款方式
                switch ($payment) {
                    case "check":
                        $transactionId = $data["checkNum"]; //支票
                        break;
                    case "remittance":
                        $transactionId = $data["remittanceNum"]; //汇款
                        break;
                    case "cash":
                        $transactionId = ""; //现金
                        break;
                    default:
                        $transactionId = "";
                        break;
                }
                $condition = OrderingOrderheader::where("orderid", $orderId)
                    ->where("status", 0);
                $orderData = $condition->first();
                $oid = $orderData["id"];
                if ($platform == OrderingUser::$platform[2] && in_array($data['products'][0]['sku'], ['Mb1212', 'mb1212', 'MB1212', 'mB1212'])) {//资料套装默认免邮
                    $rst = $condition->update(["totalpaid" => $money, "status" => 1, "paytime" => $now, "paytime_finish" => $now]); //更新订单主表
                } else {
                    $rst = $condition->update(["totalpaid" => $money, "status" => 3, "paytime" => $now, "paytime_finish" => $now]); //更新订单主表
                }

                $orderingPayment = OrderingPayment::select("id")
                    ->where("orderheader_id", $oid)
                    ->where("status", 0)
                    ->where("isdeleted", 0)
                    ->first(); //是否有支付记录

                if (empty($orderingPayment)) {
                    $paymentNumber = $this->paymentService->getPaymentNumber($orderId);
                    $paymentData = [
                        "orderheader_id" => $oid,
                        "ordernumber" => $transactionId,
                        "paymethod" => $payment,
                        "status" => 2,
                        "payment_number" => $paymentNumber,
                        "type" => 0,
                        "payamt" => $orderData["totaldue"],
                        "paytime" => $now,
                        "actualpayamt" => $money,
                        "actualpaytime" => $now,
                        "created_at" => $now,
                        "updated_at" => $now,
                        "isdeleted" => 0,
                    ]; //新增支付记录
                    $rst = OrderingPayment::insertGetId($paymentData);
                } else {
                    $paymentData = [
                        "status" => 2,
                        "ordernumber" => $transactionId,
                        "actualpayamt" => $money,
                        "actualpaytime" => $now,
                        "updated_at" => $now,
                    ]; //修改支付记录
                    $rst = OrderingPayment::where("orderheader_id", $oid)->where("status", 0)->where("isdeleted", 0)->update($paymentData);
                }
                return ['status' => true, 'msg' => "提交订单成功", 'orderid' => $oid];
            } else {
                return $result;
            }
        } catch (\Exception $exc) {
            // return ['status' => false, 'msg' => $exc->getLine() . $exc->getFile() . $exc->getMessage()];
            return ['status' => false, 'msg' => "订单处理异常"];
        }
    }

    /**
     * 取消订单
     * @param type $id 订单id,$data 表单token信息,防止重复提交
     */
    public function cancelOrder($id = 0, $data = [])
    {
        try {
            $oid = intval($id);
            $now = Carbon::now();
            $upData = [
                "updated_at" => $now,
                "status" => 2,
            ];
            $rst = null;
            $warehouseId = $this->deliveryService->getWarehouseIdByoid($oid); //库位id
            DB::transaction(function () use ($warehouseId, $oid, $upData, &$rst) {
                $now = Carbon::now();
                $orderdetail = OrderingOrderdetail::select("product_id", "quantity")->where("orderheader_id", $oid)->where("isdeleted", 0)->get(); //订单详情
                foreach ($orderdetail as $odetail) {
                    $quantity = $odetail["quantity"];
                    $inventoryData = [
                        "updated_at" => $now,
                        "sold_quantity" => DB::raw("sold_quantity+{$quantity}"), //增加可售
                        "reserve_quantity" => DB::raw("reserve_quantity-{$quantity}"), //减少预留
                    ]; //修改库存信息
                    $query = ProductInventory::where("product_id", $odetail["product_id"])->where("warehouse_id", $warehouseId)->where("isdeleted", 0);
                    $rst = $query->first();
                    $query->update($inventoryData);
                    $inventorylogData = [
                        "product_id" => $odetail["product_id"],
                        "warehouse_id" => $warehouseId,
                        "sold_quantity" => $rst["sold_quantity"],
                        "reserve_quantity" => $rst["reserve_quantity"],
                        "changed_sold_qty" => $quantity,
                        "changed_reserve_qty" => -$quantity,
                        "changed_by" => "取消订单修改库存",
                        "orderheader_id" => $oid,
                        "created_at" => $now,
                        "updated_at" => $now,
                        "isdeleted" => 0
                    ]; //库存修改日志记录
                    $inventorylogRst = ProductInventorylog::insertGetId($inventorylogData); //新增库存修改日志记录
                }

                $orderheader = OrderingOrderheader::select("orderid", "status", "paytime_finish", "orderinguser_id", "is_active")
                    ->where("id", $oid)
                    ->where("isdeleted", 0)
                    ->first();
                $ostatus = object_get($orderheader, "status", 0);
                $isCancel = $this->is_cancel($orderheader);//是否可取消
                $orderId = object_get($orderheader, "orderid", 0);
                if (!$isCancel) {
                    throw new \Exception("订单号：{$orderId}不能取消");
                }
                $orderingUser = OrderingUser::where("isdeleted", 0)->where('id', object_get($orderheader, "orderinguser_id", 0))->first();
                $platform = object_get($orderingUser, "platform", "");
                if (($ostatus == 3) && $isCancel && ($platform == OrderingUser::$platform[0])) {//微信待发货
                    $refund = env("ORDER_PAYMENT_OLD", false) ? $this->oldRefund($orderheader) : $this->refund($orderheader);
                    $refundStatus = array_get($refund, "status", false);
                    if (!$refundStatus) {
                        throw new \Exception("退款失败");
                    }
                }
                $rst = OrderingOrderheader::where("id", $oid)->where("isdeleted", 0)->where("status", 0)->update($upData);
                $used_points = OrderingOrderheader::select('orderid', 'customerid', 'use_points')->where('id', $oid)->first();
                if ($used_points['use_points'] > 0) {
                    DB::select("call points_modify(?,?,?,?,?,?,?,?,@rst)", [$used_points['customerid'], $used_points['orderid'], 0, $used_points['use_points'], 3, $used_points['orderid'], '', '']);
                    $result = DB::select("SELECT @rst");
                    $rst = $result[0]->{'@rst'};
                    if ($rst == 0) {
                        Log::error('导入失败原因：', ["DB事物取消订单积分返还失败"]);
                        throw new \Exception("操作失败！");
                    }
                }
                $rst = OrderingOrderheader::where("id", $oid)->where("isdeleted", 0)->whereIn("status", [0, 3])->update($upData);
            });
            if ($data && isset($data["token"]) && !empty($data["token"])) {
                $token = $data["token"];
                session(["{$token}" => null]);
            }
            return ['status' => true, 'msg' => "取消订单成功"];
        } catch (\Exception $exc) {
            Log::info("取消订单失败", [$exc->getMessage()]);
//            return ['status' => false, 'msg' => $exc->getMessage()];
            return ['status' => false, 'msg' => "取消订单失败"];
        }
        return ['status' => $rst, 'msg' => "取消订单成功"];
    }

    /**
     * 微信退款(old)
     */
    public function oldRefund($data)
    {
        //return ['status' => true, 'msg' => "退款成功"];
        try {
            $orderNum = object_get($data, "orderid", 0);
            $cpid = $this->commonService->getCustomerProfileId(0); //客户id
            $customerid = $this->commonService->getCustomerid($cpid);
            $rst = OrderingOrderheader::select("id", "totalpaid")
                ->where("orderid", $orderNum)
                ->where("customerid", $customerid)
                ->where("isdeleted", 0)
                ->where("status", 3)
                ->first();
            $totalpaid = object_get($rst, "totalpaid");
            $oid = object_get($rst, "id");
            if ($oid && ($totalpaid > 0)) {
                $orderingPayment = OrderingPayment::select("id", "actualpayamt", "ordernumber")
                    ->where("orderheader_id", $oid)
                    ->where("status", 2)//支付成功
                    ->where("isdeleted", 0)
                    ->first(); //是否有支付记录
                if (empty($orderingPayment)) {
                    return ['status' => false, 'msg' => "订单非法"];
                }
                $transactionId = object_get($orderingPayment, "ordernumber");
                $refundFee = object_get($orderingPayment, "actualpayamt");
                $wechat = app('wechat');
                $payment = $wechat->payment;
                $result = $payment->refundByTransactionId($transactionId, $orderNum, ($totalpaid * 100), ($refundFee * 100));
                Log::info("{$orderNum}退款结果：", [$result]);
                $return_code = array_get($result, "return_code");
                $result_code = array_get($result, "result_code");
                if ($return_code == "SUCCESS" && $result_code == "SUCCESS") {
                    $res = OrderingPayment::where('orderheader_id', $oid)->update(['status' => 4]);//更改支付表状态
                    return ['status' => true, 'msg' => "退款成功"];
                } else {
                    return ['status' => false, 'msg' => "退款失败"];
                }
            }
        } catch (\Exception $exc) {
            return ['status' => false, 'msg' => "退款失败"];
        }
    }

    /**
     * 微信退款(new)
     */
    public function refund($data)
    {
        //return ['status' => true, 'msg' => "退款成功"];
        try {
            $orderNum = object_get($data, "orderid", 0);
            $cpid = $this->commonService->getCustomerProfileId(0); //客户id
            $customerid = $this->commonService->getCustomerid($cpid);
            $rst = OrderingOrderheader::select("id", "totalpaid")
                ->where("orderid", $orderNum)
                ->where("customerid", $customerid)
                ->where("isdeleted", 0)
                ->where("status", 3)
                ->first();
            $totalpaid = object_get($rst, "totalpaid");
            $oid = object_get($rst, "id");
            if ($oid && ($totalpaid > 0)) {
                $orderingPayment = OrderingPayment::select("id", "actualpayamt", "ordernumber", "actualpaytime")
                    ->where("orderheader_id", $oid)
                    ->where("status", 2)//支付成功
                    ->where("isdeleted", 0)
                    ->first(); //是否有支付记录
                if (empty($orderingPayment)) {
                    return ['status' => false, 'msg' => "订单非法"];
                }
                $transactionId = object_get($orderingPayment, "ordernumber");
                $refundFee = object_get($orderingPayment, "actualpayamt");
                $actualpaytime = object_get($orderingPayment, "actualpaytime");
                $transdt = date("Ymd", strtotime($actualpaytime));//交易日期

                $wechat = app('wechat');
                $payment = $wechat->payment;
                $api = env("WECHAT_PAYMENT_YBS_REFUND_URL");
                $params = [
                    "merchantNo" => env("WECHAT_PAYMENT_YBS_MERCHANT_ID"),
                    "merchantId" => env("WECHAT_PAYMENT_YBS_REFUND_MERCHANT_ID"),
                    "transdt" => $transdt,
                    "transaction_id" => $transactionId,
                    "out_refund_no" => $orderNum,
                    "total_fee" => ($totalpaid * 100),
                    "refund_fee" => ($refundFee * 100),
                    "op_user_id" => env("WECHAT_PAYMENT_YBS_MERCHANT_ID"),
                    "refund_channal" => "ORIGINAL",
                ];
                $params['sign'] = refund_generate_sign($params, env("WECHAT_PAYMENT_YBS_KEY"), 'md5');
                Log::info("refund参数:", $params);
                $options = [
                    'verify' => false,
                    'body' => XML::build($params),
                    "debug" => false,
                ];
                $response = $payment->getAPI()->getHttp()->request($api, "post", $options);
                $result = (array)XML::parse($response->getBody());
                Log::info("{$orderNum}退款结果(new)：", [$result]);
                $return_status = array_get($result, "status");
                $result_code = array_get($result, "result_code");
                if (($return_status == 0) && ($result_code == 0)) {
                    $res = OrderingPayment::where('orderheader_id', $oid)->update(['status' => 4]);//更改支付表状态
                    return ['status' => true, 'msg' => "退款成功"];
                } else {
                    return ['status' => false, 'msg' => "退款失败"];
                }
            }
        } catch (\Exception $exc) {
            return ['status' => false, 'msg' => "退款失败"];
        }
    }

    /**
     * web订购的订单取消
     * @param type $id 订单id
     */
    public function cancelWebOrder($id, $data = [])
    {
        if ($data && isset($data["token"]) && !empty($data["token"])) {
            $token = $data["token"];
            if (empty(session("{$token}"))) {
                return ['status' => false, 'msg' => "请勿重复提交"];
            }
        }
        $orderheader = app(OrderingOrderheader::class)->where('orderid', $id)->where('isdeleted', 0)->where("status", 0)->first();
        if ($orderheader) {
            $orderpayments = app(OrderingPayment::class)->where('orderheader_id', $orderheader->id)->where('isdeleted', 0)->get();
            if ($orderpayments) {
                try {
                    $ret = DB::transaction(function () use ($orderpayments) {
                        foreach ($orderpayments as &$orderpayment) {
                            if ($orderpayment->status == 1) {
                                if ($orderpayment->paymethod == 'wxpay') {
                                    //构造参数
                                    $parameter = [
                                        'out_trade_no' => $orderpayment->payment_number,
                                    ];
                                    $result = $this->wxPayApi->orderQuery($parameter);
                                    log::info("微信订单查询结果：", $result);
                                    if ($result['return_code'] == 'SUCCESS' && $result['result_code'] == 'SUCCESS' && $result['trade_state'] == 'SUCCESS') {
                                        $orderid = $orderpayment->payment_number; //订单号
                                        $fee = $orderpayment->payamt; //金额
                                        //构造参数
                                        $parameter = [
                                            'out_trade_no' => $orderid,
                                            'total_fee' => $orderpayment['payamt'] * 100,
                                            'refund_fee' => $fee * 100,
                                            'out_refund_no' => env('WECHAT_PAYMENT_MERCHANT_ID') . date('YmdHis') . rand(100, 999),
                                            'op_user_id' => env('WECHAT_PAYMENT_MERCHANT_ID'),
                                        ];

                                        $result = $this->wxPayApi->refund($parameter);
                                        log::info("微信退款结果：", $result);
                                        if ($result['return_code'] == 'FAIL') {
                                            return ['status' => false, 'msg' => $result['return_msg']];
                                        } elseif ($result['result_code'] == 'FAIL') {
                                            return ['status' => false, 'msg' => $result['err_code_des']];
                                        } else {
                                            $orderpayment->status = 3;
                                            $orderpayment->save();
                                        }
                                    } else {
                                        $orderpayment->status = 4;
                                        $orderpayment->save();
                                        continue;
                                    }
                                } else {
                                    $result = $this->alipayService->query($orderpayment->payment_number);
                                    log::info("支付宝订单查询结果：", $result);
                                    if ($result['code'] != 10000 || $result['trade_status'] == 'TRADE_CLOSED') {
                                        $orderpayment->status = 4;
                                        $orderpayment->save();
                                        continue;
                                    } else {
                                        if ($result['code'] == 10000 && $result['trade_status'] == 'WAIT_BUYER_PAY') {
                                            $ret = $this->alipayService->close($orderpayment->payment_number);
                                            if ($ret['status'] == true) {
                                                $orderpayment->status = 4;
                                                $orderpayment->save();
                                                continue;
                                            }
                                        } else {
                                            $refund = $this->alipayService->refund($orderpayment->payment_number, $orderpayment->payamt);
                                            if ($refund['status'] == true) {
                                                $orderpayment->status = 3;
                                                $orderpayment->save();
                                            } else {
                                                return ['status' => false, 'msg' => $refund['msg']];
                                            }
                                        }
                                    }
                                }
                            } elseif ($orderpayment->status == 2) {
                                if ($orderpayment->paymethod == 'wxpay') {
                                    $orderid = $orderpayment->payment_number; //订单号
                                    $fee = $orderpayment->payamt; //金额
                                    //构造参数
                                    $parameter = [
                                        'out_trade_no' => $orderid,
                                        'total_fee' => $orderpayment['payamt'] * 100,
                                        'refund_fee' => $fee * 100,
                                        'out_refund_no' => env('WECHAT_PAYMENT_MERCHANT_ID') . date('YmdHis') . rand(100, 999),
                                        'op_user_id' => env('WECHAT_PAYMENT_MERCHANT_ID'),
                                    ];
                                    log::info("微信退款结果：", $parameter);
                                    $result = $this->wxPayApi->refund($parameter);
                                    log::info("微信退款结果：", $result);
                                    if ($result['return_code'] == 'FAIL') {
                                        return ['status' => false, 'msg' => $result['return_msg']];
                                    } elseif ($result['result_code'] == 'FAIL') {
                                        return ['status' => false, 'msg' => $result['err_code_des']];
                                    } else {
                                        $orderpayment->status = 3;
                                        $orderpayment->save();
//                                        log::info('chenggong',['id'=>$orderpayment->id]);
                                        continue;
                                    }
                                } else {
                                    //todo 支付宝退款
                                    $refund = $this->alipayService->refund($orderpayment->payment_number, $orderpayment->payamt);
                                    if ($refund['status'] == true) {
                                        $orderpayment->status = 3;
                                        $orderpayment->save();
                                        continue;
                                    } else {
                                        return ['status' => false, 'msg' => $refund['msg']];
                                    }
                                }
                            } elseif ($orderpayment->status == 3) {
                                if ($orderpayment->paymethod == 'wxpay') {
                                    //构造参数
                                    $parameter = [
                                        'out_trade_no' => $orderpayment->payment_number,
                                    ];
                                    $result = $this->wxPayApi->refundQuery($parameter);
                                    log::info("微信退款查询结果：", $result);
                                    if ($result['return_code'] == 'SUCCESS' && $result['result_code'] == 'SUCCESS') {
                                        $orderpayment->status = 4;
                                        $orderpayment->save();
                                        continue;
                                    }
                                } else {
                                    //todo 支付宝取消订单
                                    $result = $this->alipayService->refund_query($orderpayment->payment_number);
                                    log::info("支付宝退款查询结果：", $result);
                                    if ($result['status'] == true) {
                                        $orderpayment->status = 4;
                                        $orderpayment->save();
                                        continue;
                                    } else {
                                        return ['status' => false, 'msg' => '此订单正在取消中'];
                                    }
                                }
                            } else {
                                $orderpayment->status = 4;
                                $orderpayment->save();
                                continue;
                            }
                        }
                    });
                    if (!$ret) {
                        return $this->cancelOrder($orderheader->id, $data);
                    } else {
                        return ['status' => false, 'msg' => $ret['msg']];
                    }
                } catch (\Exception $exc) {
                    return ['status' => false, 'msg' => "取消订单失败"];
                }
            }
        } else {
            return ['status' => false, 'msg' => "此订单不允许进行该操作"];
        }
    }

    /**
     * 订单详情
     * @param type $orderid 订单id
     */
    public function getOrderDetail($orderid, $platform = "wechat", $type = 1)
    {
        $id = $orderid;
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $orderer = $this->commonService->getOrdererInfo($cpid); //订货人信息
        $customerid = $this->commonService->getCustomerid($cpid);
        $data = OrderingOrderheader::where("orderid", $id)
            ->where("customerid", $customerid)
            ->where("isdeleted", 0)
            ->first(); //订单详情
        if (!$data) {
            $arr = [
                "data" => $data,
                "status" => false,
            ];
            return compact("arr");
        }
        $giftProducts = []; //赠送产品列表
        if (!empty($data)) {
            $data["freightamt"] = number_format($data["freightamt_actual"], 2);
            $data["freightDiscount"] = bcsub($data["freightamt"], $data["total_freight"], 4);
            $data["freightamt"] = number_format($data["freightamt_actual"], 2);
            $data["totalpaid"] = number_format($data["totalpaid"], 2);
            $data["discountamt"] = number_format($data["discountamt"], 2);
            $data["ostatus"] = OrderingOrderheader::$status[$data["status"]];
        }
        $products = OrderingOrderdetail::where("orderheader_id", $data["id"])->where("isdeleted", 0)->get(); //订单列表
        $quantitysum = 0; //数量总和
        $priceSum = 0; //价格总和
        $pointSum = 0;//jf总和
        $vpsum = 0; //vp总和
        $firsttotalprice = 0; //首单价格
        $firstTotalVp = 0; //首单VP
        $now = Carbon::now();
        $address = OrderingDelivery::where("orderheader_id", $data["id"])->first(); //收货地址
        if (!empty($address)) {
            $address["Province"] = SystemRegion::select("name")->where("id", $address["province_id"])->first();
            $address["City"] = SystemRegion::select("name")->where("id", $address["city_id"])->first();
            $address["District"] = SystemRegion::select("name")->where("id", $address["district_id"])->first();
            $address['Delivery'] = SystemDatadictionary::select("labelcn")->where("type", RECEIPTDATE)->where("code", $address["deliveryschedule"])->first(); //配送时间
            $address["Express"] = DeliveryExpress::select("name", "code")->where("id", $address["express_id"])->first();
        }
        if (!empty($products)) {
            foreach ($products as $key => &$detail) {
                $quantity = $detail["quantity"];
                $product = ProductBase::select("id", "name", "picurl1", "sku")
                    ->where("id", $detail["product_id"])
//                    ->where("isdeleted", 0)
//                    ->where("startsellingdate", "<=", $now)
//                    ->where("endsellingdate", ">=", $now)
                    ->first();
                $detail["product"] = $product;
                $quantitysum += $quantity;
                // $detail["unitprice"] = number_format($detail["unitprice"], 2);
                // $detail["vp"] = number_format($detail["vp"], 2);
                $detail["vp"] = sprintf("%.2f", $detail["vp"]);
                $detail["points"] = sprintf("%.2f", $detail["points"]);
                $priceSum += $detail["unitprice"] * $quantity;
                $vpsum += $detail["vp"] * $quantity;
                $pointSum += $detail["points"] * $quantity;
                if ($detail["unitprice"] == 0 && $detail["vp"] == 0) {//赠品
                    $giftProducts[] = $detail;
                    unset($products[$key]);
                }
            }
        }
        $orderSum = $priceSum + $data["freightamt"]; //订单总额
        $orderSum = number_format($orderSum, 2);
        $priceSum = number_format($priceSum, 2);
        $vpsum = number_format($vpsum, 2);
        $pointSum = number_format($pointSum, 2);
        $arr = [
            "data" => $data,
            "status" => true,
            "products" => $products,
            "giftProducts" => $giftProducts,
            "quantitysum" => $quantitysum,
            "priceSum" => $priceSum,
            "pointSum" => $pointSum,
            "vpsum" => $vpsum,
            "address" => $address,
            "orderSum" => $orderSum,
            "orderer" => $orderer,
        ];
        return compact("arr");
    }

    /**
     * 订单收货信息查询
     * @param type $orderid 订单id
     */
    public function getOrderConsigneeDetail($orderid, $platform = "wechat", $type = 1)
    {
        $id = $orderid;
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $orderer = $this->commonService->getOrdererInfo($cpid); //订货人信息
        $customerid = $this->commonService->getCustomerid($cpid);
        $data = OrderingOrderheader::where("orderid", $id)
            ->where("customerid", $customerid)
            ->where("isdeleted", 0)
            ->first(); //订单详情
        if (!$data) {
            $arr = [
                "data" => $data,
                "status" => false,
            ];
            return compact("arr");
        }
        $address = OrderingDelivery::where("orderheader_id", $data["id"])
            ->with("province")
            ->with("city")
            ->with("district")
            ->first(); //收货地址
        if (!empty($address)) {
            $address['Delivery'] = SystemDatadictionary::select("labelcn")->where("type", RECEIPTDATE)->where("code", $address["deliveryschedule"])->first(); //配送时间
            $address["Express"] = DeliveryExpress::select("name", "code")->where("id", $address["express_id"])->first();
        }
        $months = $this->commonService->getMonths(); //订单月
        $months[] = "201804";
        $receiptdate = [];//收货时间
        $freightId = object_get($address,"express_id",0);
        if (!empty($freightId)) {
            $scheduleList = DeliveryExpress::select("delivery_schedule_list")->where("id", $freightId)->first();
            $scheduleList = explode(",", $scheduleList["delivery_schedule_list"]);
            $receiptdate = SystemDatadictionary::select("Code", "LabelCN")->where("Type", RECEIPTDATE)->whereIn("code", $scheduleList)->get(); //收货时间
        }
        $arr = [
            "data" => $data,
            "status" => true,
            "address" => $address,
            "months" => $months,
            "receiptdate" => $receiptdate,
        ];
        return compact("arr");
    }

    /**
     *  修改订单收货信息
     */
    public function postOrderConsignee($params, $platform = "wechat", $type = 1){
        try {
            $orderid = array_get($params,"orderid");
            $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
            $orderer = $this->commonService->getOrdererInfo($cpid); //订货人信息
            $customerid = $this->commonService->getCustomerid($cpid);
            $data = OrderingOrderheader::where("orderid", $orderid)
                ->where("customerid", $customerid)
                ->where("is_active",0)
                ->where("status",3)
                ->where("isdeleted", 0)
                ->first(); //订单详情
            if (!$data) {
                $arr = [
                    "data" => $data,
                    "status" => false,
                ];
                return compact("arr");
            }
            $oid = object_get($data,"id",0);
            $orderAddrData = [
                "receivername" => array_get($params,"receivername"),//收货人
                "address" => array_get($params,"address"),//详细地址
                "zipcode" => array_get($params,"zipcode"),//邮编
                "phoneno" => array_get($params,"phoneno"),//手机号
                "deliveryschedule" => array_get($params,"receiptdate"), //收货时间
            ]; //客户地址
            $month = array_get($params,"month");//订单月
            $ordermonth = in_array($month, $this->commonService->getMonths()) ? $month : date("Ym");//订单月
            $rst = DB::transaction(function () use ($orderid,$customerid,$orderAddrData,$oid,$ordermonth){
                // $rst1 =  OrderingOrderheader::where("orderid", $orderid)
                //  ->where("customerid", $customerid)
                //  ->where("isdeleted", 0)
                //  ->where("is_active",0)
                //  ->where("status",3)
                //  ->update([
                //          "ordermonth"=>$ordermonth
                //      ]);
                $rst2 = OrderingDelivery::where("orderheader_id", $oid)->update($orderAddrData);
                return true;
            });

            return ['status' => $rst, 'msg' => "收货信息修改成功"];
        }catch (\Exception $exc) {
            Log::info("收货信息修改失败:",[$exc->getMessage()]);
            return ['status' => false, 'msg' => "收货信息修改失败"];
        }

    }

    /**
     * 获取订单列表
     */
    public function getOrderList($params, $platform = "wechat", $type = 1)
    {
        $status = isset($params['status']) ? $params['status'] : '';
        $currentpage = isset($params['current']) ? $params['current'] : 1;
        $keywords = isset($params['keywords']) ? $params['keywords'] : '';
        $offset = isset($params['offset']) ? $params['offset'] : 0;
        $limit = isset($params['limit']) ? $params['limit'] : 20;
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $uid = $this->commonService->getOrderingUserId($platform, $type); //订购用户id
        $list = OrderingOrderheader::select("id", "orderid", "vp", "totaldue", "discountamt", "productamt", "status", "freightamt", "total_freight", "use_points", "paytime_finish", "orderinguser_id", "is_supple_order", "is_active")
            ->whereRaw("(orderinguser_id=? or ( orderinguser_id!=? and customerid=? and status not in (0,2)))", [$uid, $uid, $customerId]);
        if ($status == "topay") {//待付款
            $list = $list->where("status", 0);
        }
        if ($status == "pay") {//已付款
            $list = $list->whereNotIn("status", [0, 2]);
        }
        if ($keywords) {
            $list->where('orderid', $keywords);
        }
        $list = $list->where("isdeleted", 0);
        $count = $list->count();
        $list = $list->orderBy("id", "desc")
            ->skip($offset)
            ->take($limit)
            ->get();
        $paginate = [
            'total' => $count,
            'currentPage' => $currentpage,
            'lastPage' => $count % $limit == 0 ? intval($count / $limit) : intval($count / $limit) + 1,
            'perPage' => $limit,
            'offset' => $offset,
        ];
        foreach ($list as &$orders) {
            $quantitysum = 0; //数量总和
            $priceSum = 0; //价格总和
            $vpsum = 0; //vp总和
            $id = $orders["id"]; //订单id
            $products = OrderingOrderdetail::where("orderheader_id", $id)->where("isdeleted", 0)->get(); //订单列表
            foreach ($products as &$detail) {
                $quantity = $detail["quantity"];
                $product = ProductBase::select("sku", "name", "picurl1")
                    ->where("id", $detail["product_id"])
//                    ->where("isdeleted", 0)
                    ->first();
                $detail["product"] = $product;
                $quantitysum += $quantity;
                $priceSum += $detail["unitprice"] * $quantity;
                $vpsum += $detail["vp"] * $quantity;
                $vpsum = sprintf('%.2f', $vpsum);
                $detail["unitprice"] = sprintf("%.2f", $detail["unitprice"]);
            }
            $orderSum = $priceSum + $orders["freightamt"]; //订单总额
            $orders["totaldue"] = sprintf("%.2f", $orders["totaldue"]);
            $orders["products"] = $products;
            $orders["orderSum"] = $orderSum;
            $orders['vp'] = sprintf('%.2f', $orders['vp']);
            $orders["quantitysum"] = $quantitysum;
            $orders["vpsum"] = sprintf("%.2f", $orders["vpsum"]);
            $orders["pointsum"] = sprintf("%.2f", $orders["use_points"]);
            $orders["ostatus"] = OrderingOrderheader::$status[$orders["status"]];
            $orders["is_cancel"] = $this->is_cancel($orders);
        }
        return compact("list", "count", "paginate");
    }


    /**
     * 订单是否可取消
     * @param  $order 订单详情object[status,paytime_finish,orderinguser_id,is_active]
     */
    private function is_cancel($order)
    {

        $rst = 0;
        $status = object_get($order, "status");
        $orderinguser_id = object_get($order, "orderinguser_id", 0);
        $is_active = object_get($order, "is_active", 0);
        $orderingUser = OrderingUser::where("isdeleted", 0)->where('id', $orderinguser_id)->first();
        $platform = object_get($orderingUser, "platform", "");
        if ($status == 0) {
            $rst = 1;
        }
        if (($status == 3) && ($platform == OrderingUser::$platform[0]) && ($is_active == 0)) {//付款30分钟内可取消,30分钟后is_active=1
            $rst = 1;
        }

        // if($status==3&&($platform==OrderingUser::$platform[0])){
        //     $paytimeFinish =  object_get($order,"paytime_finish");
        //     $now = time();
        //     $paytimeFinish = strtotime($paytimeFinish);
        //     if(($paytimeFinish+1800)>=$now){//付款30分钟内可取消
        //         $rst =1;
        //     }
        // }
        return $rst;
    }

    /**
     * 获取开票订单列表
     */
    public function getInvoiceOrderList($params, $platform = "wechat", $type = 1)
    {
        $offset = isset($params['offset']) ? $params['offset'] : 0;
        $limit = isset($params['limit']) ? $params['limit'] : 20;
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $uid = $this->commonService->getOrderingUserId($platform, $type); //订购用户id
        $list = OrderingOrderheader::select("id", "orderid", "vp", "totalpaid", "discountamt", "productamt", "status", "freightamt", "total_freight")
            ->whereRaw("((orderinguser_id=? or ( orderinguser_id!=? and customerid=?))  and status=1)", [$uid, $uid, $customerId])//已完成
            ->whereRaw("(select id from ordering_invoice where customerid=? and FIND_IN_SET(ordering_orderheader.id,order_ids) and isdeleted=0 limit 1) is null", [$customerId])
            ->where("isdeleted", 0);
        $count = $list->count();
        $list = $list->orderBy("id", "desc")
            ->skip($offset)
            ->take($limit)
            ->get();
        foreach ($list as &$orders) {
            $quantitysum = 0; //数量总和
            $id = $orders["id"]; //订单id
            $products = OrderingOrderdetail::where("orderheader_id", $id)->where("isdeleted", 0)->get(); //订单列表
            foreach ($products as &$detail) {
                $quantity = $detail["quantity"];
                $quantitysum += $quantity;
                $product = ProductBase::select("sku", "name", "picurl1")
                    ->where("id", $detail["product_id"])
//                    ->where("isdeleted", 0)
                    ->first();
                $detail["product"] = $product;
            }
            $orders["products"] = $products;
            $orders["quantitysum"] = $quantitysum;
            $orders["choice"] = false;
        }
        if (!$list->isEmpty()) {
            $list = $list->toArray();
            $list = array_values($list);
        }
        return compact("list", "count");
    }

    /**
     * 根据订单获取发票金额
     */
    public function getInvoiceMoney($params, $platform = "wechat", $type = 1)
    {
        $oids = empty($params["oids"]) ? [] : explode(",", $params["oids"]); //多订单开票
        $orderNum = array_get($params, "orderNum", ""); //nts单二维码扫码过来的订单
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $uid = $this->commonService->getOrderingUserId($platform, $type); //订购用户id
        $list = OrderingOrderheader::select("id", "orderid", "totalpaid", "productamt", "taxamt", "freightamt", "discountamt", "adjust_amount", "total_freight")
            ->whereRaw("((orderinguser_id=? or ( orderinguser_id!=? and customerid=?)) and status=1)", [$uid, $uid, $customerId])
            ->whereRaw("(select id from ordering_invoice where customerid=? and FIND_IN_SET(ordering_orderheader.id,order_ids) and isdeleted=0 limit 1) is null", [$customerId]);
        if ($orderNum) {
            $list = $list->where("orderid", $orderNum);
        } else {
            $list = $list->whereIn("id", $oids);
        }
        $list = $list->where("isdeleted", 0)
            ->orderBy("id", "desc")
            ->get();
        return compact("list");
    }

    /**
     * 封装xml
     * @param type $appId
     * @param type $contentPassword
     * @param type $interfaceCode
     * @param type $contentData
     * @return string
     */
    protected function getSendToTaxXML($appId, $contentPassword, $interfaceCode, $contentData)
    {
        $myAES = new MyAES();
        $str = '';
        $str .= "<?xml version='1.0' encoding='UTF-8' ?>";
        $str .= "<interface xmlns=\"\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:schemaLocation=\"http://www.chinatax.gov.cn/tirip/dataspec/interfaces.xsd\" version=\""
            . "DZFPQZ0.2" . "\"> ";
        $str .= "<globalInfo>";
        $str .= "<appId>" . $appId . "</appId><interfaceId></interfaceId>";
        $str .= "<interfaceCode>" . $interfaceCode . "</interfaceCode>" . "<requestCode>DZFPQZ</requestCode>";
        $str .= "<requestTime>" . date("Y-m-d h:i:s") . "</requestTime>" . "<responseCode>Ds</responseCode>";
        $str .= "<dataExchangeId>DZFPQZ" . $interfaceCode . date("Y-m-d") . rand(111111111, 999999999) . "</dataExchangeId>";
        $str .= "</globalInfo>";
        $str .= "<returnStateInfo>";
        $str .= "<returnCode></returnCode>";
        $str .= "<returnMessage></returnMessage>";
        $str .= "</returnStateInfo>";
        $str .= "<Data>";
        $str .= "<dataDescription>";
        $str .= "<zipCode>0</zipCode>";
        $str .= "</dataDescription>";
        $str .= "<content>";
        $content = preg_replace('# #', '', base64_encode($contentData)); //base64加密请求报文并去空格
        $str .= $content;
        $str .= "</content>";
        $str .= "<contentKey>";
        $contentKey = $myAES->encrypt(md5($content), $contentPassword);
        $str .= $contentKey;
        $str .= "</contentKey>";
        $str .= "</Data>";
        $str .= "</interface>";
        return $str;
    }

    /**
     * 获取发票明细
     * @param array $arr
     * @return string
     */
    protected function getFPKJ($arr = [])
    {
        $xmList = <<<XML
                                    <COMMON_FPKJ_XMXX>
                                     <FPHXZ>{$arr["FPHXZ"]}</FPHXZ>
                                     <SPBM>{$arr["SPBM"]}</SPBM>
                                     <ZXBM></ZXBM>
                                     <YHZCBS></YHZCBS>
                                     <LSLBS></LSLBS>
                                     <ZZSTSGL></ZZSTSGL>
                                     <XMMC>{$arr["XMMC"]}</XMMC>
                                     <GGXH></GGXH>
                                     <DW></DW>
                                     <XMSL>{$arr["XMSL"]}</XMSL>
                                     <XMDJ>{$arr["XMDJ"]}</XMDJ>
                                     <XMJE>{$arr["XMJE"]}</XMJE>
                                     <SL>{$arr["SL"]}</SL>
                                     <SE>{$arr["SE"]}</SE>
                                     <BY1>备用字段1</BY1>
                                     <BY2>备用字段2</BY2>
                                     <BY3>备用字段3</BY3>
                                     <BY4>备用字段4</BY4>
                                     <BY5>备用字段5</BY5>
                                     </COMMON_FPKJ_XMXX>
XML;
        return $xmList;
    }

    /**
     * 提交发票申请
     */
    public function postInvoice($params, $platform = "wechat", $type = 1)
    {
        try {
            error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
            $invoice_order_amt = 0; //开票金额 (价税合计（不允许有误差）)
            $HJSE = 0; //合计税额（允许6分钱误差）
            $HJJE = 0; //合计金额  (不含税金额（允许1分钱误差))
            $KCE = 0; //扣除额
            $order_ids = [];
            $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
            $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
            $list = $this->getInvoiceMoney($params, $platform, $type);
            $size = 0; //发票列表项数
            $xmList = ""; //发票项
            $SL = 0.16;
            foreach ($list["list"] as $invoice) {
                $id = $invoice["id"];
                $invoice_kce = 0;
                if (!in_array($id, $order_ids)) {
                    $order_ids[] = $id;
                    $invoice_order_amt += $invoice["totalpaid"]; //价税合计
                    $invoice_kce += $invoice["discountamt"] + $invoice["adjust_amount"]; //当前扣除额
                    $KCE += $invoice_kce;
                    $products = OrderingOrderdetail::where("orderheader_id", $id)->where("isdeleted", 0)->get(); //订单列表
                    $pSum = count($products);
                    $priPercents = 0;
                    $productIds = $products->pluck('product_id');
                    $productPriceItems = ProductPricebase::whereIn('product_id',$productIds)
                        ->where('isdeleted', 0)
                        ->get()->keyBy('product_id');
                    // 实付金额
                    $orderingPayment = OrderingPayment::where('orderheader_id', $id)->where("isdeleted", 0)->first();
                    $actualPayamt = $orderingPayment->actualpayamt;
                    // 计算产品实际总价 productamt
                    $orderTopay = OrderingOrderheader::select('productamt')->where('id',$id)->where("isdeleted", 0)->first();
                    $productDiscountAvg = [];
                    $usedPercent = 0;
                    // 计算每个产品折扣差额
                    foreach ($products as $i => $item) {
                        $productPrice = $productPriceItems->get($item->product_id);
                        if ($i == count($products) - 1) {
                            $percent = 1 - $usedPercent;
                        } else {
                            $percent = ($productPrice->price / $actualPayamt);
                            $usedPercent += $percent;
                        }
                        $productDiscountAvg[$item->id] = $percent * ($orderTopay->productamt-$actualPayamt);
                    }

                    foreach ($products as $key => &$detail) {
                        if ($detail["unitprice"] == 0.01) {
                            continue;
                        }
                        $productPrice = $this->getProductPrice($detail->product_id);

                        $size++;
                        $quantity = $detail["quantity"];
                        $XMDJ = sprintf("%.2f", ($detail["unitprice"] - $detail["taxamt"])); //单价
                        $XMJE = sprintf("%.2f", ($XMDJ * $quantity)); //金额
//                        $SL = sprintf("%.2f", $detail["taxrate"]); //税率
                        $SE = sprintf("%.2f", ($XMJE * $SL)); //税额



                        $product = ProductBase::select("sku", "name", "barcode")
                            ->where("id", $detail["product_id"])
//                          ->where("isdeleted", 0)
                            ->first();
                        $product = ProductBase::select("sku", "name", "barcode", "is_series")
                            ->where("id", $detail["product_id"])
//                          ->where("isdeleted", 0)
                            ->first();
                        $is_series = object_get($product, "is_series", 0);
                        $product_id = object_get($detail, "product_id", 0);
                        if ($is_series != 1 && $detail["unitprice"] != 0)    {
                            $HJSE += $SE; //合计税额
                            $HJJE += $XMJE; //合计金额
                            $listArr = [
                                "SPBM" => object_get($product, "barcode"),
                                "XMMC" => "{$product["sku"]} - {$product["name"]}",
                                "XMSL" => "$quantity",
                                "XMDJ" => "$XMDJ",
                                "XMJE" => "$XMJE",
                                "SL" => "$SL",
                                "SE" => "$SE",
                                "FPHXZ" => (($invoice_kce > 0) ? 2 : 0),
                            ];
                            $xmList .= $this->getFPKJ($listArr);
                        }
                        if ($is_series == 1 && $detail["unitprice"] != 0) {//套装
                            //将套装单产品(循环)计算出来,根据series_id,获取单品的sku
                            $seriesName = $this->getSeriesSkuAndName($detail->product_id);
                            /**
                             * select a.sku,a.quantity, b.price, b.taxamt, b.taxrate,taxcategory, (quantity*price) as total_price
                             * from product_seriesdetail a inner join product_pricebase b on a.series_product_id = b.product_id
                             * where a.series_id = 25 and a.isdeleted = 0 and b.isdeleted = 0\G
                             */

                            /** @var Collection $result */
                            $rawTotalPrice = \Illuminate\Support\Facades\DB::raw('(quantity * price) as total_price');
                            $result = \Illuminate\Support\Facades\DB::table('product_seriesdetail as a')
                                ->select('a.quantity', 'b.price', 'b.taxamt', 'b.taxrate', 'b.taxcategory', 'c.sku', 'c.name', 'c.barcode', $rawTotalPrice)
                                ->join('product_pricebase as b', 'a.series_product_id', '=', 'b.product_id')
                                ->join('product_base as c', 'a.series_product_id', '=', 'c.id')
                                ->where('a.series_id', $product_id)
                                ->where('a.isdeleted', 0)
                                ->where('b.isdeleted', 0)
                                ->get();

                            // 套装总价
                            $total_price = $result->sum('total_price');
                            // 计算总价
                            $orderDiscount = array_get($productDiscountAvg, $detail->id, 0);

                            $diff = object_get($detail, "price_diff", 0);//获取套装和套装产品差异价(+/-)
                            $diff = (float)sprintf("%.2f", $diff - $orderDiscount);
                            $priPercents = 0;
                            foreach ($result as $i =>$item) {
                                $size++;
                                $quantity = $item->quantity;
                                $XMDJ = sprintf("%.2f", ($item->price - $item->taxamt)); //单价
                                $XMJE = sprintf("%.2f", ($XMDJ * $quantity)); //金额
//                                $SL = sprintf("%.2f", $item->taxrate); //税率
                                $SE = sprintf("%.2f", ($XMJE * $SL)); //税额
                                $HJSE += $SE; //合计税额
                                $HJJE += $XMJE; //合计金额
                                $product = ProductBase::select("sku", "name", "barcode")
                                    ->where("id", $detail["product_id"])
//                          ->where("isdeleted", 0)
                                    ->first();
                                $listArr = [
                                    "SPBM" => object_get($product, "barcode"),
                                    "XMMC" => "{$seriesName->name}*{$item->name}",
                                    "XMSL" => "$quantity",
                                    "XMDJ" => "$XMDJ",
                                    "XMJE" => "$XMJE",
                                    "SL" => "$SL",
                                    "SE" => "$SE",
                                    "FPHXZ" => (($diff > 0) ? 0 : 2),
                                ];
                                $xmList .= $this->getFPKJ($listArr);


                                if ($i == count($result) - 1) {
                                    $priPercent = (1 - $priPercents);
                                } else {
                                    $priPercent = (float)sprintf("%.2f", c / $total_price); //产品价格百分比
                                    $priPercents += $priPercent;
                                }
                                $price =$priPercent * $diff;
                                $XMDJ = sprintf("%.2f",$price); //单价
                                $XMJE = sprintf("%.2f", $XMDJ); //金额
//                                $SL = sprintf("%.2f", $item->taxrate); //税率
                                $SE = sprintf("%.2f", ($XMJE * $SL)); //税额
                                $HJSE += $SE; //合计税额
                                $HJJE += $XMJE; //合计金额
//                                dd($HJJE);
                                $be_listArr = [
                                    "SPBM" => object_get($item, "barcode"),
                                    "XMMC" => "{$seriesName->name}*{$item->name}",
//                                    "XMSL" => $item->quantity,
//                                    "XMDJ" =>$XMDJ,
                                    "XMJE" => $XMJE,
                                    "SL" => $SL,
                                    "SE" => $SE,
                                    "FPHXZ" => (($diff > 0) ? 0 : 1),
                                ];
                                $xmList .= $this->getFPKJ($be_listArr);
                            }
                        }
                        if ($detail["unitprice"] == 0) {//赠品
                            if($is_series == 1 && $detail["unitprice"] == 0){//如果赠送的赠品是套装，将套装单品循环出来，然后相互销掉
                                $seriesName = $this->getSeriesSkuAndName($detail->product_id);
                                $rawTotalPrice = \Illuminate\Support\Facades\DB::raw('(quantity * price) as total_price');
                                $result = \Illuminate\Support\Facades\DB::table('product_seriesdetail as a')
                                    ->select('a.quantity', 'b.price', 'b.taxamt', 'b.taxrate', 'b.taxcategory', 'c.sku', 'c.name', 'c.barcode', $rawTotalPrice)
                                    ->join('product_pricebase as b', 'a.series_product_id', '=', 'b.product_id')
                                    ->join('product_base as c', 'a.series_product_id', '=', 'c.id')
                                    ->where('a.series_id', $product_id)
                                    ->where('a.isdeleted', 0)
                                    ->where('b.isdeleted', 0)
                                    ->get();
                                foreach ($result as $i =>$item) {
                                    $size++;
                                    $quantity = $item->quantity;
                                    $XMDJ = sprintf("%.2f", ($item->price - $item->taxamt)); //单价
                                    $XMJE = sprintf("%.2f", ($XMDJ * $quantity)); //金额
//                                $SL = sprintf("%.2f", $item->taxrate); //税率
                                    $SE = sprintf("%.2f", ($XMJE * $SL)); //税额
                                    $product = ProductBase::select("sku", "name", "barcode")
                                        ->where("id", $detail["product_id"])
//                          ->where("isdeleted", 0)
                                        ->first();
                                    $listArr = [
                                        "SPBM" => object_get($product, "barcode"),
                                        "XMMC" => "{$seriesName->name}*{$item->name}",
                                        "XMJE" => "$XMJE",
                                        "SL" => "$SL",
                                        "SE" => "$SE",
                                        "FPHXZ" => 2,
                                    ];
                                    $xmList .= $this->getFPKJ($listArr);

                                    $be_listArr = [
                                        "SPBM" => object_get($item, "barcode"),
                                        "XMMC" => "{$seriesName->name}*{$item->name}",
                                        "XMJE" => -$XMJE,
                                        "SL" => $SL,
                                        "SE" => -$SE,
                                        "FPHXZ" => 1 ,
                                    ];
                                    $xmList .= $this->getFPKJ($be_listArr);
                                }
                            }if ($is_series != 1){
                                $size +=2;
                                $productPrice = ProductPricebase::select('price','taxrate')->whereIn('product_id',$productIds)
                                    ->where('isdeleted', 0)
                                    ->first();
                                $quantity = $detail["quantity"];
                                $XMDJ = sprintf("%.2f", $productPrice->price); //单价
                                $XMJE = sprintf("%.2f", ($XMDJ * $quantity)); //金额
//                            $SL = sprintf("%.2f", $productPrice["taxrate"]); //税率
                                $SE = sprintf("%.2f", ($XMJE * $SL)); //税额

                                //被折扣行
                                $be_listArr = [
                                    "SPBM" => object_get($product, "barcode"),
                                    "XMMC" => "{$product["sku"]} - {$product["name"]}",
                                    "XMSL" => "$quantity",
                                    "XMDJ" => "$XMDJ",
                                    "XMJE" => "$XMJE",
                                    "SL" => "$SL",
                                    "SE" => "$SE",
                                    "FPHXZ" => "2",
                                ];
                                //折扣行
                                $listArr = [
                                    "SPBM" => object_get($product, "barcode"),
                                    "XMMC" => "{$product["sku"]} - {$product["name"]}",
//                                "XMSL" => "$quantity",
//                                "XMDJ" => "-$XMDJ",
                                    "XMJE" => "-$XMJE",
                                    "SL" => "$SL",
                                    "SE" => "-$SE",
                                    "FPHXZ" => "1",
                                ];
                                $xmList .= $this->getFPKJ($be_listArr);
                                $xmList .= $this->getFPKJ($listArr);
                            }
                        }

                        if (($invoice_kce > 0) && ($SL > 0) && $is_series !=1 && $detail["unitprice"] != 0) {//扣除
                            $size++;
                            //价格百分百
                            if ($pSum == ($key + 1)) {//最后一行
                                $priPercent = (1 - $priPercents);
                            } else {
                                $priPercent = sprintf("%.2f", (($detail["unitprice"] * $quantity) / $invoice["productamt"])); //产品价格百分比
                                $priPercents += $priPercent;
                            }
                            $zkPrice = ($priPercent * $invoice_kce);
                            $XMJE = sprintf("%.2f", ($zkPrice / (1 + $SL))); //税前金额
                            $SE = sprintf("%.2f", ($XMJE * $SL)); //税费
                            $listArr = [
                                "SPBM" => object_get($product, "barcode"),
                                "FPHXZ" => 1,
                                "XMMC" => "{$product["sku"]} - {$product["name"]}",
                                "XMJE" => "-$XMJE",
                                "SL" => "$SL",
                                "SE" => "-$SE"
                            ];
                            $HJSE -= $SE; //合计税额
                            $HJJE -= $XMJE; //合计金额
                            $xmList .= $this->getFPKJ($listArr);
                        }
                    }
//                    if ($invoice["freightamt"] > 0) {//配送费
//                        $SL = 0.17;
//                        $size++;
//                        $XMJE = sprintf("%.2f", ($invoice["freightamt"] / (1 + $SL))); //税前金额
//                        $SE = sprintf("%.2f", ($XMJE * $SL)); //税费
//                        $listArr = [
//                            "SPBM" => "1010101030000000000",
//                            "FPHXZ" => 0,
//                            "XMMC" => "配送费",
//                            "XMJE" => "$XMJE",
//                            "SL" => "$SL",
//                            "SE" => "$SE"
//                        ];
//                        $HJSE += $SE; //合计税额
//                        $HJJE += $XMJE; //合计金额
//                        $xmList .= $this->getFPKJ($listArr);
//                    }
                }
            }
            $invoice_order_amt = sprintf("%.2f", $invoice_order_amt);
            $KCE = sprintf("%.2f", $KCE); //扣除额
            $JSHJ = $HJSE + $HJJE; //价税合计
            $data = [
                "status" => 1,
                "customerid" => $customerId,
                "order_ids" => implode(",", $order_ids),
                "invoice_type" => ((int)$params["header"] == 1 ? 1 : 0),
                "invoice_order_amt" => $JSHJ,
                "address" => array_get($params, "address", ""),
                "phone_code" => array_get($params, "phone_code", ""),
                "bank" => array_get($params, "bank", ""),
                "bank_code" => array_get($params, "bank_code", ""),
                "phoneno" => array_get($params, "mobile", ""),
                "email" => array_get($params, "email", ""),
                "isdeleted" => 0
            ];
            $GMF_MC = "个人";
            if ($data["invoice_type"] == 1) {
                $data["invoice_header"] = $params["invoiceHeader"];
                $data["tax_registration_num"] = $params["registrationNum"];
                $GMF_MC = $params["invoiceHeader"];
            }
            $result = [];

            //初始化appid
            $appId = env("INVOICE_APPID");

            //初始化密钥
            $contentPassword = env("INVOICE_CONTENT_PASS");
            $FPQQLSH = date("YmdHis");
            //初始化开具报文
            //蓝票
            $XSF_NSRSBH = env("INVOICE_NSRSBH");
            $XSF_MC = env("INVOICE_XSF_MC");
            $invoiceList = config("invoice");

            $XSF_DZDH = array_get($invoiceList, "XSF_DZDH", "");
            $XSF_YHZH = array_get($invoiceList, "XSF_YHZH", "");
            $KPR = array_get($invoiceList, "KPR", "");
            $SKR = array_get($invoiceList, "SKR", "");
            $FHR = array_get($invoiceList, "FHR", "");
            $contentData = <<<XML
        <REQUEST_COMMON_FPKJ class="REQUEST_COMMON_FPKJ">
        <FPQQLSH>{$FPQQLSH}</FPQQLSH>
        <KPLX>0</KPLX>
        <ZSFS>0</ZSFS>
        <XSF_NSRSBH>{$XSF_NSRSBH}</XSF_NSRSBH>
        <XSF_MC>{$XSF_MC}</XSF_MC>
        <XSF_DZDH>{$XSF_DZDH}</XSF_DZDH>
        <XSF_YHZH>{$XSF_YHZH}</XSF_YHZH>
        <GMF_NSRSBH>{$data["tax_registration_num"]}</GMF_NSRSBH>
        <GMF_MC>{$GMF_MC}</GMF_MC>
        <GMF_DZDH>{$data["address"]}{$data["phone_code"]}</GMF_DZDH>
        <GMF_YHZH>{$data["bank"]}{$data["bank_code"]}</GMF_YHZH>
        <GMF_SJH>{$data["phoneno"]}</GMF_SJH>
        <GMF_DZYX>{$data["email"]}</GMF_DZYX>
        <FPT_ZH></FPT_ZH>
        <WX_OPENID></WX_OPENID>
        <KPR>{$KPR}</KPR>
        <SKR>{$SKR}</SKR>
        <FHR>{$FHR}</FHR>
        <YFP_DM></YFP_DM>
        <YFP_HM></YFP_HM>
        <JSHJ>{$JSHJ}</JSHJ>
        <HJJE>{$HJJE}</HJJE>
        <HJSE>{$HJSE}</HJSE>
        <KCE>{$KCE}</KCE>
        <BZ></BZ>
        <HYLX>行业类型</HYLX>
        <BY1>备用字段1</BY1>
        <BY2>备用字段2</BY2>
        <BY3>备用字段3</BY3>
        <BY4>备用字段4</BY4>
        <BY5>备用字段5</BY5>
        <BY6>备用字段6</BY6>
        <BY7>备用字段7</BY7>
        <BY8>备用字段8</BY8>
        <BY9>备用字段9</BY9>
        <BY10>备用字段10</BY10>
        <COMMON_FPKJ_XMXXS class="COMMON_FPKJ_XMXX" size="{$size}">
        {$xmList}
        </COMMON_FPKJ_XMXXS>
      </REQUEST_COMMON_FPKJ>
XML;
            Log::info("开票请求报文：", [$contentData]);
            $interfaceCode = "DFXJ1001"; //初始化接口文档(蓝票)
            $reqXML = $this->getSendToTaxXML($appId, $contentPassword, $interfaceCode, $contentData);
            //软证书配置变量，此处软证书配置可以不填写
            $cerarr = array(
                'local_cert' => env("INVOICE_CA"),
                'passphrase' => env('INVOICE_PASS'),
            );

            //使用soapclient调用发票webservice接口
            $client = new \SoapClient(env("INVOICE_SOAP_URL"), $cerarr);
            //调用doService方法
            $result = $client->doService([
                'in0' => $reqXML
            ]);
            //将stdClass Object转换成array格式
            $str = object_array($result, true);

            $fa_info = $str['out'];
//            return $fa_info;
//            echo "返回报文:" . $fa_info;               //请求成功后返回信息
            //将xml格式转换为array格式
            $xml = simplexml_load_string($fa_info, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
            $xmlData = json_decode(json_encode($xml), true);
            Log::info("开票返回报文：", $xmlData);
//            return $xmlData;
            $returnCode = $xmlData['returnStateInfo']['returnCode'];
            $returnMessage = $xmlData['returnStateInfo']['returnMessage'];
//            return $returnMessage;
//            echo "返回代码：" . $returnCode . ",返回信息：" . $returnMessage;
            if ($returnCode == '0000') {
                //base64解密
                $xml_info = base64_decode($xmlData['Data']['content']); //base64解密后的发票详细信息
                $xml_info = simplexml_load_string($xml_info, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
                $xml_info = object_array($xml_info);
                $data["data_json"] = json_encode($xml_info, JSON_UNESCAPED_UNICODE);
                $data["invoice_num"] = $xml_info["FP_HM"];
                $invoiceId = OrderingInvoice::insertGetId($data); //新增发票申请记录
                return ['status' => true, 'msg' => "发票申请成功", 'invoiceId' => $invoiceId, "url" => $xml_info["SP_URL"]];
            } else {
                return ['status' => false, 'msg' => $returnMessage];
            }
        } catch (\Exception $exc) {
            Log::info("开票异常：", [$exc->getMessage()]);
            return ['status' => false, 'msg' => "发票申请异常"];
        }
    }

    /**
     * 发送发票信息到新邮箱
     */
    public function putInvoiceEmail($params, $platform = "wechat", $type = 1)
    {
        try {
            $id = $params["id"];
            $email = $params["newEmail"];
            $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
            $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
            $result = OrderingInvoice::whereId($id)
                ->where("customerid", $customerId)
                ->where("isdeleted", 0)
                ->update([
                    "email" => $email
                ]);
            return ['status' => true, 'msg' => "发票发送成功"];
        } catch (\Exception $exc) {
            return ['status' => false, 'msg' => "发票发送失败" . $exc->getMessage()];
        }
    }

    /**
     * 获取发票详情根据主键id
     */
    public function getInvoiceDetail($invoiceId, $platform = "wechat", $type = 1)
    {
        $id = (int)$invoiceId;
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $data = OrderingInvoice::whereId($id)
            ->where("customerid", $customerId)
            ->where("isdeleted", 0)
            ->first();
        return compact("data");
    }

    /**
     * 发票申请历史
     */
    public function getInvoiceRecords($params, $platform = "wechat", $type = 1)
    {
        $offset = isset($params['offset']) ? $params['offset'] : 0;
        $limit = isset($params['limit']) ? $params['limit'] : 20;
        $month = isset($params['month']) ? $params['month'] : "";
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
//        $months = $this->commonService->getRecentlyMonths();
        $months = [
            '1' => '1个月内',
            '3' => '3个月内',
            '6' => '6个月内',
        ];
        $query = OrderingInvoice::select("id", "status", "invoice_type", "invoice_num", "invoice_order_amt", "created_at", "invoice_header", "data_json")
            ->where("customerid", $customerId)
            ->where("isdeleted", 0);
        if (!empty($month)) {
            $onemonth = date('Y-m-d H:i:s', strtotime("-{$month} month"));
            $query = $query->whereRaw("(created_at > '{$onemonth}')");
//            $query = $query->whereRaw("(DATE_FORMAT(created_at,'%Y%m')=?)", [$month]);
        }
        $count = $query->count();
        $list = $query->orderBy("id", "desc")
            ->skip($offset)
            ->take($limit)
            ->get();
        foreach ($list as &$row) {
            $row["data_json"] = json_decode($row["data_json"], true);
            $row["kprq"] = date("Y-m-d H:i:s", strtotime($row["data_json"]["KPRQ"]));
        }
        return compact("list", "count", "months");
    }

    /**
     * 获取订单列表
     */
    public function getWebOrderList($params, $platform = "wechat", $type = 1)
    {
        $status = isset($params['status']) ? $params['status'] : '';
        $keywords = isset($params['keywords']) ? $params['keywords'] : '';
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $uid = $this->commonService->getOrderingUserId($platform, $type); //订购用户id
        $list = OrderingOrderheader::select("id", "orderid", "vp", "totaldue", "discountamt", "productamt", "status", "freightamt", "total_freight")
            ->whereRaw("(orderinguser_id=? or ( orderinguser_id!=? and customerid=? and status not in (0,2)))", [$uid, $uid, $customerId])->where("isdeleted", 0);
        if ($status == "topay") {
            $list->where("status", 0);
        } else {
            if ($status == "finish") {
                $list->whereNotIn("status", [0, 2]);
            }
        }
        if ($keywords) {
            $list->where('orderid', $keywords);
        }
        $limit = isset($params["per_page"]) ? intval($params["per_page"]) : 10;
        $current = isset($params["current_page"]) ? intval($params["current_page"]) : '';
        $results = $list->paginate($limit, $columns = ['*'], $pageName = 'page', $current);
        $response = [
            'pagination' => [
                'total' => $results->total(),
                'per_page' => $results->perPage(),
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'from' => $results->firstItem(),
                'to' => $results->lastItem()
            ]
        ];
        $orderList = $list->orderBy("id", "desc")->get();
        foreach ($orderList as &$orders) {
            $quantitysum = 0; //数量总和
            $priceSum = 0; //价格总和
            $vpsum = 0; //vp总和
            $firsttotalprice = 0; //首单价格
            $firstTotalVp = 0; //首单VP
            $id = $orders["id"]; //订单id
            $products = OrderingOrderdetail::where("orderheader_id", $id)->where("isdeleted", 0)->get(); //订单列表
            foreach ($products as &$detail) {
                $quantity = $detail["quantity"];
                $product = ProductBase::select("sku", "name", "picurl1")
                    ->where("id", $detail["product_id"])
//                    ->where("isdeleted", 0)
                    ->first();
                $detail["product"] = $product;
                $quantitysum += $quantity;
                $priceSum += $detail["unitprice"] * $quantity;
                $vpsum += $detail["vp"] * $quantity;
                $detail["unitprice"] = sprintf("%.2f", $detail["unitprice"]);
            }
            $orderSum = $priceSum + $orders["freightamt"]; //订单总额
            $orderSum = sprintf("%.2f", $orderSum);
            $orders["totaldue"] = sprintf("%.2f", $orders["totaldue"]);
            $orders["products"] = $products;
            $orders["orderSum"] = $orderSum;
            $orders["quantitysum"] = $quantitysum;
            $orders["vpsum"] = $vpsum;
            $orders["ostatus"] = OrderingOrderheader::$status[$orders["status"]];
            $detail = $this->getOrderDetail($orders->orderid, "pc", 1);
            if ($detail['arr']['address']->expressno) {
                $numbers = explode(';', $detail['arr']['address']->expressno);
                $expressCode = 'auto';
                if ($detail['arr']['address']->Express->code) {
                    $expressCode = $detail['arr']['address']->Express->code;
                }
                $detail = json_decode($this->expressServive->queryExpress($numbers[0], $expressCode), true);
                if ($detail['status'] == 0 && isset($detail['result'])) {
                    $orders['expressdetail'] = $detail['result']['list'];
                }
            }
        }
//        $topayList = $orderList->where("status", 0);
//        $finishList = $orderList->where("status", '!=', 0)->where("status", '!=', 2);
        return compact("response", "orderList");
    }

    /**
     * 获取订单编号
     * @param type $uid 用户id
     * @param type $provId 省份id
     * @param type $freightId 快递公司id
     * @return type
     */
    public function getOrderId($uid, $provId, $freightId)
    {
        $warehouseId = $this->deliveryService->getWarehouseId($provId, $freightId); //库位id
        $warehouse = DeliveryWarehouse::select("code")->whereId($warehouseId)->first();
        $orderSeedNum = $this->commonService->getSeedNum(ORDERING, $uid); //订单种子
        $orderId = empty($warehouse) ? "" : mb_substr($warehouse["code"], 0, 2) . 'H' . $orderSeedNum;
        if (env("ORDER_ID_TEST", false)) {//是否测试订单
            $orderId .= "test";
        }
        return $orderId;
    }

    /**
     *  确认付款
     * @param type $orderid 订单id
     */
    public function orderPay($orderid, $parames, $platform = "wechat", $type = 1)
    {
        $id = $orderid; //订单号
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $orderer = $this->commonService->getOrdererInfo($cpid); //订货人信息
        $uid = $this->commonService->getOrderingUserId($platform, $type); //订购用户id
        $data = OrderingOrderheader::where("orderid", $id)->where("orderinguser_id", $uid)->where("isdeleted", 0)->first();
        if (!$data) {
            $arr = [
                "data" => $data,
                "status" => false,
            ];
            return compact("arr");
        }
        $deadline = object_get($data, "deadline", "");
        $differ = ""; //可用多少秒
        if (!empty($data)) {
            $data["totaldue"] = sprintf("%.2f", $data["totaldue"]);
        }
        if ($deadline) {//有结束日期
            $differ = strtotime($deadline) - strtotime($data["createtime"]);
        }
        $goods = OrderingOrderdetail::leftJoin("product_base", "ordering_orderdetail.product_id", "=", "product_base.id")
            ->where("ordering_orderdetail.orderheader_id", "{$data["id"]}")
            ->where("ordering_orderdetail.isdeleted", 0)
            ->first();
        $orderingPayment = OrderingPayment::select("orderheader_id")
            ->where("orderheader_id", $data["id"])
            ->where("status", 1)
            ->where("isdeleted", 0)
            ->first(); //是否有支付记录
        $arr = [
            "status" => true,
            "data" => $data,
            "goods" => $goods,
            "parames" => $parames,
            "orderer" => $orderer,
            "paymentInfo" => $orderingPayment,
            "differ" => $differ,
            "paymentOld" => env("ORDER_PAYMENT_OLD", false)
        ];
        return compact("arr");
    }

    /*     * 再来一单
     * @param $orderid 订单id
     * @param string $platform
     * @param int $type
     * @return array|int
     */

    public function orderCopy($orderid, $platform = "wechat", $type = 1)
    {
        $id = $orderid;
        $data = OrderingOrderheader::where("orderid", $id)->where("isdeleted", 0)->first(); //订单详情
        $products = OrderingOrderdetail::where("orderheader_id", $data["id"])->where("isdeleted", 0)->get(); //订单列表
        if (!empty($products)) {
            foreach ($products as $key => &$detail) {
                if ($detail["unitprice"] == 0 && $detail["vp"] == 0) {//赠品
                    unset($products[$key]);
                    continue;
                }
                $quantity = $detail["quantity"];
                $pid = $detail['product_id'];
                $cartData['pid'] = $pid;
                $cartData['quantity'] = $quantity;
                $result = $this->cartService->postCart($cartData);
            }
            return $result;
        }
    }

    /**
     * 补单获取订单编号
     * @param type $uid 用户id
     * @param type $provId 省份id
     * @param type $freightId 快递公司id
     * @return string
     */
    public function getOrderId_supply($uid)
    {
        $warehouseId = "01";//库位id
//        $warehouse = DeliveryWarehouse::select("code")->whereId($warehouseId)->first();
        $orderSeedNum = $this->commonService->getSeedNum(ORDERING, $uid); //订单种子
        $orderId = $warehouseId . 'H' . $orderSeedNum;
        return $orderId;
    }

    //后台补单提交
    public function supplyOrderPost($data, $platform = "admin", $type = 1)
    {
        try {
            $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //卡号
            $now = Carbon::now();
            $month = getMonth(0); //订单月
            $actualpay = isset($data["actualpay"]) ? $data["actualpay"] : "";
            $orderProducts = $data['products'] ? $data['products'] : [];
            $priceSum = 0;
            $vpSum = 0;
            foreach ($orderProducts as $key => $orderProduct) {
                if (!isset($orderProduct["id"]))
                    continue;
                $orderProductId = isset($orderProduct["id"]) ? $orderProduct["id"] : 0;
                $productIds[] = $orderProductId;
                $quantity = isset($orderProduct["quantity"]) ? (int)$orderProduct["quantity"] : 0;
                if (!is_numeric($quantity) || $quantity <= 0) {
                    continue;
                }
                $price = $orderProduct["price"]["Price"];
                $vp = $orderProduct["price"]["vp"];
                $priceSum += $price * $quantity; //产品金额
                $vpSum += $vp * $quantity; //产品vp
                //订单详情表数据
                $orderDetailData[] = [
                    "orderheader_id" => "",
                    "product_id" => $orderProductId,
                    "quantity" => $quantity,
                    "UnitPrice" => sprintf("%.2f", $price),
                    "vp" => sprintf("%.2f", $vp),
                    "Points" => 0,
                    "taxamt" => 0,
                    "taxrate" => 0,
                    "isdeleted" => 0
                ];
            }
            $created_by = $data['username'] ?? '';
            $actualpay = $data['actualpay'] ?? '';
            $priceSum = sprintf("%.4f", $priceSum);//订单总额
            $vpSum = sprintf("%.4f", $vpSum);
            $totalpaid = $data['actualpay'];
            //订单主表数据
            $remark = "";
            $uid = $this->commonService->getOrderingUserId($platform, $type, $customerId); //订购用户id
            $orderId = $this->getOrderId_supply($uid); //订单编号
            if (empty($orderId)) {
                throw new \Exception("订单号异常");
            }
            $orderHeaderData = [
                "orderinguser_id" => $uid,
                "orderid" => $orderId,
                "productamt" => $priceSum, //产品总额
                "commission_productamt" => $priceSum, //产品总额
                "taxamt" => 0, //税费
                "vp" => $vpSum, //产品vp
                "discountamt" => 0, //折扣总额
                "freightamt" => 0, //运费
                "freightamt_actual" => 0, //实际运费
                "totaldue" => $priceSum, //应付
                "totalpaid" => $actualpay, //实付
                "adjust_amount" => 0, //其他调整
                "insurance" => 0, //保险费
                "total_freight" => 0, //配送费
                "ordermonth" => $month,
                "createtime" => $now,
                "created_at" => $now,
                "created_by" => $created_by,
                "deliverytype" => 0, //配送方式
                "customerid" => $customerId, //客户资格证号
                "remark" => $remark,
                "status" => 1,
                "is_supple_order" => 1,
                "paytime_finish" => $now
            ];
            $orderRst = 0;
            DB::transaction(function () use (
                $orderHeaderData,
                $orderDetailData,
                &$orderRst
            ) {
                $headerRst = OrderingOrderheader::insertGetId($orderHeaderData); //新增订单数据
                $orderRst = $headerRst;
                $orderData = [];
                $orderDetailData = array_map(function ($orderData) use ($headerRst) {
                    $newData = $orderData;
                    $newData["orderheader_id"] = $headerRst;
                    return $newData;
                }, $orderDetailData); //回调添加订单id
                $detailRst = OrderingOrderdetail::insert($orderDetailData); //新增订单明细
            });
        } catch (\Exception $exc) {
            Log::error("订单处理异常", ["文件:" . $exc->getFile(), "行号:" . $exc->getLine(), "异常信息:" . $exc->getMessage()]);
            return ['status' => false, 'msg' => "订单处理异常" . $exc->getMessage()];
        }
        return ['status' => true, 'msg' => "提交订单成功", 'orderid' => $orderRst,];

    }


    /**
     * @param $productId
     * @return ProductPricebase
     */
    public function getProductPrice($productId)
    {
        return ProductPricebase::where('product_id',$productId)
            ->where('isdeleted', 0)
            ->first();
    }

    /**
     * @param $product_id
     * @return sku,name
     */
    public function getSeriesSkuAndName($productId){
        //获取套装名称,sku
        $seriesName = ProductBase::select('sku','name')
            ->where('id',$productId)
            ->where('isdeleted', 0)
            ->first();
        return $seriesName;
    }

    /**
     * select * from customer_profile where subtype in ('GT','MT','PT') and isdeleted = 0;
     * get middle customer
     */
    public function getMiddleCus($params, $platform = "wechat", $type = 1){
        $cpid = $this->commonService->getCustomerProfileId(0, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $uid = $this->commonService->getOrderingUserId($platform, $type); //订购用户id
        //判断用户id是否满足购买条件
        return $this->canBuyTicket($cpid);
    }

    /**
     * 判断用户是否有购买资格
     */
    public function canBuyTicket($uid){
        $middleData = CustomerProfile::select('subtype')
            ->where('id',$uid)
            ->where('isdeleted', 0)
            ->first();
        $subtype = object_get($middleData,"subtype");
        if ($subtype == "GT" || $subtype == 'MT' || $subtype == 'PT'){
            return ['status' => 1, "msg" => "可购买", "type" => $subtype];
        }else{
            return ['status' => 0, "msg" => "非购买级别", "type"=>$subtype];
        }
    }


    /**
     * 门票购买详情页面
     * 返回门票信息以及价格
     * @param $data
     * @return array
     */
    public function getTicketDetail($data, $platform = "wechat", $type = 1){
        $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //资格证号
        $cpid = $this->commonService->getCustomerProfileId($customerId, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $ticketDetail = $this->getOrderTicketDetail($data["pid"]);
        $tickets = $this->getTicketNums($data["pid"],$customerId);
        $arr = [
            "status" => true,
            "data" => $ticketDetail,
            "tickets" => $tickets
        ];
        return compact("arr");
    }

    /**
     * 获取门票数量详情
     * @param $pid
     * @param $customerId
     * @return array
     */
    public function getTicketNums($pid,$customerId){
        $totalQuantity = \Illuminate\Support\Facades\DB::table('ordering_orderdetail as a')//查询该用户购买该产品的数量
        ->select('a.*')
            ->join('ordering_orderheader as b', 'a.orderheader_id', '=', 'b.id')
            ->where('a.product_id',$pid)
            ->where('b.customerid',$customerId)
            ->where('a.isdeleted', 0)
            ->sum('a.quantity');//已购数量

        $points = CustomerVolume::select('volumepoint')
            ->where('ordermonth','>=','201804')
            ->where('ordermonth','<=','201805')
            ->where('isdeleted', 0)
            ->where('customerid',$customerId)
            ->where('volumetype','JX')
            ->orderBy('updated_at')
            ->first();//获取该用户的市场管理绩效
        if ($points->volumepoint >= 4000){//查询该用户可购买的数量
            $tickets = 30;
        }elseif ($points->volumepoint >= 10000){
            $tickets = 50;
        }else{
            $tickets = 15;
        }
        $ticket_buy = $tickets-$totalQuantity;
        $data = [
            "haveBuy" => $totalQuantity,
            "allTickets" => $tickets,
            "canBuy" => $ticket_buy
        ];
        return $data;
    }

    /**
     * 获取门票信息
     * @param $pid
     * @return array|null|\stdClass
     */
    public function getOrderTicketDetail($pid){
        $ticketDetail = \Illuminate\Support\Facades\DB::table('product_base as a')
            ->select('b.price','a.*','c.time','c.address')
            ->join('product_tickets as c','a.id','=','c.product_id')
            ->join('product_pricebase as b', 'a.id', '=', 'b.product_id')
            ->where('a.id',$pid)
            ->where('a.isdeleted', 0)
            ->first();
        return $ticketDetail;
    }
    /**
     * 返回门票详情以及门票数量以及订票人姓名
     * @param $data
     * @param string $platform
     * @param int $type
     * @return array
     */
    public function getTicketOrderInfo($data, $platform = "wechat", $type = 1){
        $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //资格证号
        $cpid = $this->commonService->getCustomerProfileId($customerId, $platform); //客户id
        $customerId = $this->commonService->getCustomerid($cpid); //客户资格证号
        $orderer = $this->commonService->getOrdererInfo($cpid); //订货人信息
        $is_active = object_get($orderer, "is_active", 0);
        if (!$is_active) {
            $arr["status"] = false;
            return compact("arr");
        }
        $pid = $data["pid"];
        $result = $this->canBuyTicket($cpid);//判断该用户是否具有购买资格
        if ($result['status'] == 1){
            $ticketDetail = $this->getOrderTicketDetail($pid);
            $tickets = $this->getTicketNums($pid,$customerId);
            if ($data["ticketNum"] > $tickets["canBuy"]){
                $arr = [
                    "status"=> false,
                    "data" => '购买票数过多'
                ];
                return compact("arr");
            }
            $arr = [
                "status" => true,
                "data" => $ticketDetail,
                "tickets" => $tickets,
                "ticketNum"=>$data["ticketNum"],
                'name' =>$orderer["namecn"]
            ];
            return compact("arr");
        }
        $arr = [
            "status"=> false,
            "data" => '无购买权限'
        ];
        return compact("arr");
    }

    /**
     * 立即购买
     * 传入商品id和商品数量，返回订单信息
     * @param $data
     * @param string $platform
     * @param int $type
     * @return array
     * @throws \Throwable
     */
    public function postTicketOrderData($data, $platform = "wechat", $type = 1)
    {
        try {
            $customerId = isset($data["customerId"]) ? $data["customerId"] : ""; //资格证号
            $now = Carbon::now();
            $uid = $this->commonService->getOrderingUserId($platform, $type, $customerId); //订购用户id
            $cid = $uid; //缓存关联id
            $cpid = $this->commonService->getCustomerProfileId($customerId, $platform); //客户id
            $customerid = $this->commonService->getCustomerid($cpid); //客户资格证号
            $cusInfo = $this->commonService->getCustomerInfo($cpid); //客户信息
            $is_active = object_get($cusInfo, "is_active", 0); //是否在册，1在册，0退出
            if ($platform == OrderingUser::$platform[2] && (empty($customerId) || empty($cusInfo) || $now > $cusInfo["renewdate"] || ($is_active == 0) || $this->blacklistService->existInBlack($cpid))) {
                return ['status' => false, 'msg' => "资格证号非法"];
            }
            $orderId = $this->commonService->getSeedNum(ORDERING, $uid); //订单种子
            $created_by = $uid; //创建人
            $nums = $data["ticketNum"]; //立即购买才有数量
            $pid = $data['pid'];
            if (!empty($data["nums"])) {
                $validator = Validator::make($data, [
                    'nums' => [
                        'required',
                        'regex:/^([0-9]|[1-9][0-9]*)$/'
                    ],
                ]);
                if ($validator->fails()) {
                    $arr = ['status' => false, 'msg' => '参数非法'];
                    return compact("arr");
                }
                $nums = intval($data["nums"]);
            }
            $month = $this->commonService->getMonths(); //订单月; //订单月
            $totalPrice = 0; //产品价格
            $orderDetailData = []; //订单详情
            $orderInventoryData = []; //库存详情
            $orderTickets = [];//订单门票详情
            $productIds = []; //购买产品id集合
            if (!empty($nums) && !empty($pid)) {//立即购买
                if (!is_numeric($nums) || $nums <= 0) {
                    return ['status' => false, 'msg' => "参数非法"];
                }
                $price = ProductPricebase::select("price", "taxamt", "taxrate")
                    ->where("product_id", $pid)
                    ->where("isdeleted", 0)
                    ->first(); //基本价格
                $price_base = [
                    'Price' => object_get($price, 'price'),
                ];
                if (object_get($price, 'Price')) {
                    $validator = Validator::make($price_base, [
                        'Price' => [
                            'required',
                            'regex:/(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/'
                        ],
                    ]);
                    if ($validator->fails()) {
                        return ['status' => false, 'msg' => '参数非法'];
                    }
                }
                $totalPrice += $price["price"] * $nums; //产品金额
                $prod = $this->commonService->getProduct($pid, 0, 0, $platform, $type); //产品
                $ticketDetail = $this->getOrderTicketDetail($pid);//门票详情
                $stock = $prod["stock"]; //产品库存
                if ($nums > $stock) {
                    return ['status' => false, 'msg' => "产品{$prod["sku"]} - {$prod["name"]}库存不足"];
                }
                $productIds[] = $pid;
                $orderInventoryData[] = $this->commonService->modifyInventory($pid, $nums, 0, 0, $platform, $type); //待修改库存信息
                $orderInventoryData = array_filter($orderInventoryData);
                $price_diff = 0;
                $orderDetailData[] = [
                    "orderheader_id" => "",
                    "product_id" => $pid,
                    "quantity" => $nums,
                    "UnitPrice" => $price["price"],
                    "vp" => 0,
                    "points" => 0,
                    "price_diff" => $price_diff,
                    "taxamt" => $price["taxamt"],
                    "taxrate" => $price["taxrate"],
                    "isdeleted" => 0
                ];
                $orderTickets[] = [
                    "orderheader_id" => "",
                    "time" => object_get($ticketDetail,"time"),
                    "address" => object_get($ticketDetail,"address"),
                    "isdeleted" => 0
                ];
            } else {
                return ['status' => false, 'msg' => "提交订单失败"];
            }

            if (empty($orderDetailData)) {
                return ['status' => false, 'msg' => "请选择一张"];
            }

            $totaldue = $totalPrice; //产品金额
            if ($totaldue <= 0) {
                return ['status' => false, 'msg' => "应付金额非法"];
            }
            $ordermonth = in_array($month, $this->commonService->getMonths()) ? $month : date("Ym");
            if ($platform == OrderingUser::$platform[2]) {
                $ordermonth = empty($month) ? data("Ym") : $month; //订单月
            }

            $totalPrice = sprintf("%.2f", $totalPrice);
            $totalPoint = 0;
            $totaldue = sprintf("%.2f", $totaldue);
            $orderHeaderData = [
                "orderinguser_id" => $uid,
                "orderid" => $orderId,
                "productamt" => $totalPrice, //产品总额
                "commission_productamt" => $totalPrice, //产品总额
                "taxamt" => 0, //税费
                "vp" => 0, //产品vp
                "discountamt" => 0, //折扣总额
                "freightamt" => 0, //运费
                "freightamt_actual" => 0, //实际运费
                "totaldue" => $totalPrice, //应付
                "totalpaid" => $totalPrice, //实付
                "adjust_amount" => 0, //其他调整
                "insurance" => 0, //保险费
                "total_freight" => 0, //配送费
                "ordermonth" => $month,
                "createtime" => $now,
                "created_at" => $now,
                "created_by" => $created_by,
                "deliverytype" => 0, //配送方式
                "customerid" => $customerid, //客户资格证号
                "status" => 1,
                "is_supple_order" => 1,
                "paytime_finish" => $now
            ];
            DB::transaction(function () use (
                $orderId,
                $customerid,
                $totalPoint,
                $uid,
                $orderInventoryData,
                $data,
                $nums,
                $orderHeaderData,
                $orderDetailData,
                $orderTickets,
                &$orderid,
                $cid,
                $cpid,
                $now,
                $type,
                $platform
            ) {
                $headerRst = OrderingOrderheader::insertGetId($orderHeaderData); //新增订单数据
                $orderData = [];
                $orderDetailData = array_map(function ($orderData) use ($headerRst) {
                    $newData = $orderData;
                    $newData["orderheader_id"] = $headerRst;
                    return $newData;
                }, $orderDetailData); //回调添加订单id
                $orderTickets = array_map(function ($orderData) use ($headerRst) {
                    $newData = $orderData;
                    $newData["orderheader_id"] = $headerRst;
                    return $newData;
                }, $orderTickets); //回调添加订单id
                $detailRst = OrderingOrderdetail::insert($orderDetailData); //新增订单明细
                $ticketRst = OrderingTickets::insert($orderTickets);//新增订单门票信息
                $orderAddrData["orderheader_id"] = $headerRst;
                $orderPackage["orderheader_id"] = $headerRst;
                if (!empty($firstorder) && $headerRst) {//首单有数据，更新首单表
                    $firstRst = OrderingFirstorder::where("id", $firstorder["id"])->update(["firstorderheader_id" => $headerRst]); //首单表更新
                }
                if (!empty($orderInventoryData)) {//更新产品库存，增加日志记录
                    foreach ($orderInventoryData as $orderInventory) {
                        $productNum = $orderInventory["product_num"];
                        $inventoryData = [
                            "updated_at" => $now,
                            "sold_quantity" => DB::raw("sold_quantity-{$productNum}"),
                            "reserve_quantity" => DB::raw("reserve_quantity+{$productNum}"),
                        ]; //修改库存信息
                        $query = ProductInventory::whereId($orderInventory["id"])
                            ->where("sold_quantity", ">=", $productNum)
                            ->update($inventoryData);
                        if (!$query) {
                            throw new \Exception("产品id：{$orderInventory["product_id"]},数量：{$productNum},库存不足");
                        }
                        $inventorylogData = [
                            "product_id" => $orderInventory["product_id"],
                            "warehouse_id" => $orderInventory["inventory"]["warehouse_id"],
                            "sold_quantity" => $orderInventory["inventory"]["sold_quantity"],
                            "reserve_quantity" => $orderInventory["inventory"]["reserve_quantity"],
                            "changed_sold_qty" => -$orderInventory["product_num"],
                            "changed_reserve_qty" => $orderInventory["product_num"],
                            "changed_by" => "{$platform}提交订单修改库存",
                            "orderheader_id" => $headerRst,
                            "created_at" => $now,
                            "updated_at" => $now,
                            "isdeleted" => 0
                        ]; //库存修改日志记录
                        $inventorylogRst = ProductInventorylog::insertGetId($inventorylogData); //新增库存修改日志记录
                    }
                }
                $orderid = $headerRst;
            });
        } catch (\Exception $exc) {
            return ['status' => false, 'msg' => $exc->getLine() . $exc->getFile() . $exc->getMessage()];
            Log::error("订单处理异常", ["文件:" . $exc->getFile(), "行号:" . $exc->getLine(), "异常信息:" . $exc->getMessage()]);
            return ['status' => false, 'msg' => "订单处理异常"];
        }
        return ['status' => true, 'msg' => "提交订单成功", 'orderid' => $orderId];
    }

}
