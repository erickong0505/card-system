<?php
namespace App\Http\Controllers\Shop; use App\Card; use App\Category; use App\Library\FundHelper; use App\Library\Helper; use App\Library\LogHelper; use App\Product; use App\Library\Response; use Gateway\Pay\Pay as GatewayPay; use App\Library\Geetest; use App\Mail\ProductCountWarn; use App\System; use Carbon\Carbon; use Illuminate\Http\Request; use App\Http\Controllers\Controller; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Log; use Illuminate\Support\Facades\Mail; class Pay extends Controller { public function __construct() { define('SYS_NAME', config('app.name')); define('SYS_URL', config('app.url')); define('SYS_URL_API', config('app.url_api')); } private $payApi = null; public function goPay($spdf16c9, $spf922d7, $spba5919, $sp74e49b, $spb08dc3) { try { $sp7c2170 = json_decode($sp74e49b->config, true); $sp7c2170['payway'] = $sp74e49b->way; GatewayPay::getDriver($sp74e49b)->goPay($sp7c2170, $spf922d7, $spba5919, $spba5919, $spb08dc3); return self::renderResultPage($spdf16c9, array('success' => false, 'title' => trans('shop.please_wait'), 'msg' => trans('shop.please_wait_for_pay'))); } catch (\Exception $sp54a0c6) { if (config('app.debug')) { return self::renderResultPage($spdf16c9, array('msg' => $sp54a0c6->getMessage() . '<br>' . str_replace('
', '<br>', $sp54a0c6->getTraceAsString()))); } return self::renderResultPage($spdf16c9, array('msg' => $sp54a0c6->getMessage())); } } function buy(Request $spdf16c9) { $sp9a5e61 = $spdf16c9->input('customer'); if (strlen($sp9a5e61) !== 32) { return self::renderResultPage($spdf16c9, array('msg' => '提交超时，请刷新购买页面并重新提交<br><br>
当前网址: ' . $spdf16c9->getQueryString() . '
提交内容: ' . var_export($sp9a5e61) . ', 提交长度:' . strlen($sp9a5e61) . '<br>
若您刷新后仍然出现此问题. 请加网站客服反馈')); } if (System::_getInt('vcode_shop_buy') === 1) { try { $this->validateCaptcha($spdf16c9); } catch (\Throwable $sp54a0c6) { return self::renderResultPage($spdf16c9, array('msg' => trans('validation.captcha'))); } } $spf26f7e = (int) $spdf16c9->input('category_id'); $spfb3e15 = (int) $spdf16c9->input('product_id'); $sp051e12 = (int) $spdf16c9->input('count'); $spd85029 = $spdf16c9->input('coupon'); $sp5a4f57 = $spdf16c9->input('contact'); $spb57c6e = $spdf16c9->input('contact_ext') ?? null; $sp39031e = !empty(@json_decode($spb57c6e, true)['_mobile']); $sp6cc800 = (int) $spdf16c9->input('pay_id'); if (!$spf26f7e || !$spfb3e15) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.required'))); } if (strlen($sp5a4f57) < 1) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.contact.required'))); } $sp055b52 = null; if (System::_getInt('order_query_password_open')) { $sp055b52 = $spdf16c9->input('query_password'); if (strlen($sp055b52) < 1) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.query_password.required'))); } if (strlen($sp055b52) < 6 || Helper::isWakePassword($sp055b52)) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.query_password.weak'))); } } $spb98da4 = Category::findOrFail($spf26f7e); $sp94204a = Product::where('id', $spfb3e15)->where('category_id', $spf26f7e)->where('enabled', 1)->with(array('user'))->first(); if ($sp94204a == null || $sp94204a->user == null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.not_found'))); } if (!$sp94204a->enabled) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.not_on_sell'))); } if ($sp94204a->password_open) { if ($sp94204a->password !== $spdf16c9->input('product_password')) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.password_error'))); } } else { if ($spb98da4->password_open) { if ($spb98da4->password !== $spdf16c9->input('category_password')) { if ($spb98da4->getTmpPassword() !== $spdf16c9->input('category_password')) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.category.password_error'))); } } } } if ($sp051e12 < $sp94204a->buy_min) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.buy_min', array('num' => $sp94204a->buy_min)))); } if ($sp051e12 > $sp94204a->buy_max) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.buy_max', array('num' => $sp94204a->buy_max)))); } if ($sp94204a->count < $sp051e12) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.out_of_stock'))); } $sp74e49b = \App\Pay::find($sp6cc800); if ($sp74e49b == null || !$sp74e49b->enabled) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.pay.not_found'))); } $sp40022d = $sp94204a->price; if ($sp94204a->price_whole) { $sp8d7490 = json_decode($sp94204a->price_whole, true); for ($spc25c52 = count($sp8d7490) - 1; $spc25c52 >= 0; $spc25c52--) { if ($sp051e12 >= (int) $sp8d7490[$spc25c52][0]) { $sp40022d = (int) $sp8d7490[$spc25c52][1]; break; } } } $spaca64f = $sp051e12 * $sp40022d; $spb08dc3 = $spaca64f; $spb7003d = 0; $sp94fbbe = null; if ($sp94204a->support_coupon && strlen($spd85029) > 0) { $spd84bc1 = \App\Coupon::where('user_id', $sp94204a->user_id)->where('coupon', $spd85029)->where('expire_at', '>', Carbon::now())->whereRaw('`count_used`<`count_all`')->get(); foreach ($spd84bc1 as $spb52873) { if ($spb52873->category_id === -1 || $spb52873->category_id === $spf26f7e && ($spb52873->product_id === -1 || $spb52873->product_id === $spfb3e15)) { if ($spb52873->discount_type === \App\Coupon::DISCOUNT_TYPE_AMOUNT && $spb08dc3 >= $spb52873->discount_val) { $sp94fbbe = $spb52873; $spb7003d = $spb52873->discount_val; break; } if ($spb52873->discount_type === \App\Coupon::DISCOUNT_TYPE_PERCENT) { $sp94fbbe = $spb52873; $spb7003d = (int) round($spb08dc3 * $spb52873->discount_val / 100); break; } } } if ($sp94fbbe === null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.coupon.invalid'))); } $spb08dc3 -= $spb7003d; } $sp645a80 = (int) round($spb08dc3 * $sp74e49b->fee_system); $sp2da83a = $spb08dc3 - $sp645a80; $spe08a2a = $sp39031e ? System::_getInt('sms_price', 10) : 0; $spb08dc3 += $spe08a2a; $spba4f7c = $sp051e12 * $sp94204a->cost; $spf922d7 = \App\Order::unique_no(); try { DB::transaction(function () use($sp94204a, $spf922d7, $sp94fbbe, $sp5a4f57, $spb57c6e, $sp055b52, $sp9a5e61, $sp051e12, $spba4f7c, $spaca64f, $spe08a2a, $spb7003d, $spb08dc3, $sp74e49b, $sp645a80, $sp2da83a) { if ($sp94fbbe) { $sp94fbbe->status = \App\Coupon::STATUS_USED; $sp94fbbe->count_used++; $sp94fbbe->save(); $sp1c2837 = '使用优惠券: ' . $sp94fbbe->coupon; } else { $sp1c2837 = null; } $spaf5db5 = new \App\Order(array('user_id' => $sp94204a->user_id, 'order_no' => $spf922d7, 'product_id' => $sp94204a->id, 'product_name' => $sp94204a->name, 'count' => $sp051e12, 'ip' => Helper::getIP(), 'customer' => $sp9a5e61, 'contact' => $sp5a4f57, 'contact_ext' => $spb57c6e, 'query_password' => $sp055b52, 'cost' => $spba4f7c, 'price' => $spaca64f, 'sms_price' => $spe08a2a, 'discount' => $spb7003d, 'paid' => $spb08dc3, 'pay_id' => $sp74e49b->id, 'fee' => $sp645a80, 'system_fee' => $sp645a80, 'income' => $sp2da83a, 'status' => \App\Order::STATUS_UNPAY, 'remark' => $sp1c2837, 'created_at' => Carbon::now())); $spaf5db5->saveOrFail(); }); } catch (\Throwable $sp54a0c6) { Log::error('Shop.Pay.buy 下单失败', array('exception' => $sp54a0c6)); return self::renderResultPage($spdf16c9, array('msg' => trans('shop.pay.internal_error'))); } if ($spb08dc3 === 0) { $this->shipOrder($spdf16c9, $spf922d7, $spb08dc3, null); return redirect()->away(route('pay.result', array($spf922d7), false)); } $spba5919 = $spf922d7; return $this->goPay($spdf16c9, $spf922d7, $spba5919, $sp74e49b, $spb08dc3); } function pay(Request $spdf16c9, $spf922d7) { $spaf5db5 = \App\Order::whereOrderNo($spf922d7)->first(); if ($spaf5db5 == null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.order.not_found'))); } if ($spaf5db5->status !== \App\Order::STATUS_UNPAY) { return redirect('/pay/result/' . $spf922d7); } $spec8eaa = 'pay: ' . $spaf5db5->pay_id; $sp74e49b = $spaf5db5->pay; if (!$sp74e49b) { \Log::error($spec8eaa . ' cannot find Pay'); return $this->renderResultPage($spdf16c9, array('msg' => trans('shop.pay.not_found'))); } $spec8eaa .= ',' . $sp74e49b->driver; $sp7c2170 = json_decode($sp74e49b->config, true); $sp7c2170['payway'] = $sp74e49b->way; $sp7c2170['out_trade_no'] = $spf922d7; try { $this->payApi = GatewayPay::getDriver($sp74e49b); } catch (\Exception $sp54a0c6) { \Log::error($spec8eaa . ' cannot find Driver: ' . $sp54a0c6->getMessage()); return $this->renderResultPage($spdf16c9, array('msg' => trans('shop.pay.driver_not_found'))); } if ($this->payApi->verify($sp7c2170, function ($spf922d7, $spd1ad1b, $sp841c55) use($spdf16c9) { try { $this->shipOrder($spdf16c9, $spf922d7, $spd1ad1b, $sp841c55); } catch (\Exception $sp54a0c6) { $this->renderResultPage($spdf16c9, array('success' => false, 'msg' => $sp54a0c6->getMessage())); } })) { \Log::notice($spec8eaa . ' already success' . '

'); return redirect('/pay/result/' . $spf922d7); } if ($spaf5db5->created_at < Carbon::now()->addMinutes(-System::_getInt('order_pay_timeout_minutes', 5))) { return $this->renderResultPage($spdf16c9, array('msg' => trans('shop.order.expired'))); } $sp94204a = Product::where('id', $spaf5db5->product_id)->where('enabled', 1)->first(); if ($sp94204a == null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.not_on_sell'))); } $sp94204a->setAttribute('count', count($sp94204a->cards) ? $sp94204a->cards[0]->count : 0); if ($sp94204a->count < $spaf5db5->count) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.out_of_stock'))); } $spba5919 = $spf922d7; return $this->goPay($spdf16c9, $spf922d7, $spba5919, $sp74e49b, $spaf5db5->paid); } function qrcode(Request $spdf16c9, $spf922d7, $sp32d7d2) { $spaf5db5 = \App\Order::whereOrderNo($spf922d7)->with('product')->first(); if ($spaf5db5 == null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.order.not_found'))); } if ($spaf5db5->created_at < Carbon::now()->addMinutes(-System::_getInt('order_pay_timeout_minutes', 5))) { return $this->renderResultPage($spdf16c9, array('msg' => trans('shop.order.expired'))); } if ($spaf5db5->product_id !== \App\Product::ID_API) { $sp94204a = $spaf5db5->product; if ($sp94204a == null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.not_found'))); } if ($sp94204a->count < $spaf5db5->count) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.product.out_of_stock'))); } } if (strpos($sp32d7d2, '..')) { return $this->msg(trans('shop.you_are_sb')); } return view('pay/' . $sp32d7d2, array('pay_id' => $spaf5db5->pay_id, 'name' => $spaf5db5->product_id === \App\Product::ID_API ? $spaf5db5->api_out_no : $spaf5db5->product->name . ' x ' . $spaf5db5->count . '件', 'amount' => $spaf5db5->paid, 'qrcode' => $spdf16c9->get('url'), 'id' => $spf922d7)); } function qrQuery(Request $spdf16c9, $sp6cc800) { $spc67fbe = $spdf16c9->input('id'); if (isset($spc67fbe[5])) { return self::payReturn($spdf16c9, $sp6cc800, $spc67fbe); } else { return Response::fail('order_no error'); } } function payReturn(Request $spdf16c9, $sp6cc800, $spf922d7 = null) { $spec8eaa = 'payReturn: ' . $sp6cc800; \Log::debug($spec8eaa); $sp74e49b = \App\Pay::where('id', $sp6cc800)->first(); if (!$sp74e49b) { return $this->renderResultPage($spdf16c9, array('success' => 0, 'msg' => trans('shop.pay.not_found'))); } $spec8eaa .= ',' . $sp74e49b->driver; if ($spf922d7 && isset($spf922d7[5])) { $spaf5db5 = \App\Order::whereOrderNo($spf922d7)->firstOrFail(); if ($spaf5db5 && ($spaf5db5->status === \App\Order::STATUS_PAID || $spaf5db5->status === \App\Order::STATUS_SUCCESS)) { \Log::notice($spec8eaa . ' already success' . '

'); if ($spdf16c9->ajax()) { return self::renderResultPage($spdf16c9, array('success' => 1, 'data' => '/pay/result/' . $spf922d7), array('order' => $spaf5db5)); } else { return redirect('/pay/result/' . $spf922d7); } } } try { $this->payApi = GatewayPay::getDriver($sp74e49b); } catch (\Exception $sp54a0c6) { \Log::error($spec8eaa . ' cannot find Driver: ' . $sp54a0c6->getMessage()); return $this->renderResultPage($spdf16c9, array('success' => 0, 'msg' => trans('shop.pay.driver_not_found'))); } $sp7c2170 = json_decode($sp74e49b->config, true); $sp7c2170['out_trade_no'] = $spf922d7; $sp7c2170['payway'] = $sp74e49b->way; Log::debug($spec8eaa . ' will verify'); if ($this->payApi->verify($sp7c2170, function ($spd50ae5, $spd1ad1b, $sp841c55) use($spdf16c9, $spec8eaa, &$spf922d7) { $spf922d7 = $spd50ae5; try { Log::debug($spec8eaa . " shipOrder start, order_no: {$spf922d7}, amount: {$spd1ad1b}, trade_no: {$sp841c55}"); $this->shipOrder($spdf16c9, $spf922d7, $spd1ad1b, $sp841c55); Log::debug($spec8eaa . ' shipOrder end, order_no: ' . $spf922d7); } catch (\Exception $sp54a0c6) { Log::error($spec8eaa . ' shipOrder Exception: ' . $sp54a0c6->getMessage(), array('exception' => $sp54a0c6)); } })) { Log::debug($spec8eaa . ' verify finished: 1' . '

'); if ($spdf16c9->ajax()) { return self::renderResultPage($spdf16c9, array('success' => 1, 'data' => '/pay/result/' . $spf922d7)); } else { return redirect('/pay/result/' . $spf922d7); } } else { Log::debug($spec8eaa . ' verify finished: 0' . '

'); return $this->renderResultPage($spdf16c9, array('success' => 0, 'msg' => trans('shop.pay.verify_failed'))); } } function payNotify(Request $spdf16c9, $sp6cc800) { $spec8eaa = 'payNotify pay_id: ' . $sp6cc800; Log::debug($spec8eaa); $sp74e49b = \App\Pay::where('id', $sp6cc800)->first(); if (!$sp74e49b) { Log::error($spec8eaa . ' cannot find PayModel'); echo 'fail'; die; } $spec8eaa .= ',' . $sp74e49b->driver; try { $this->payApi = GatewayPay::getDriver($sp74e49b); } catch (\Exception $sp54a0c6) { Log::error($spec8eaa . ' cannot find Driver: ' . $sp54a0c6->getMessage()); echo 'fail'; die; } $sp7c2170 = json_decode($sp74e49b->config, true); $sp7c2170['payway'] = $sp74e49b->way; $sp7c2170['isNotify'] = true; Log::debug($spec8eaa . ' will verify'); $sp8ee8d3 = $this->payApi->verify($sp7c2170, function ($spf922d7, $spd1ad1b, $sp841c55) use($spdf16c9, $spec8eaa) { try { Log::debug($spec8eaa . " shipOrder start, order_no: {$spf922d7}, amount: {$spd1ad1b}, trade_no: {$sp841c55}"); $this->shipOrder($spdf16c9, $spf922d7, $spd1ad1b, $sp841c55); Log::debug($spec8eaa . ' shipOrder end, order_no: ' . $spf922d7); } catch (\Exception $sp54a0c6) { Log::error($spec8eaa . ' shipOrder Exception: ' . $sp54a0c6->getMessage()); } }); Log::debug($spec8eaa . ' notify finished: ' . (int) $sp8ee8d3 . '

'); die; } function result(Request $spdf16c9, $spf922d7) { $spaf5db5 = \App\Order::where('order_no', $spf922d7)->first(); if ($spaf5db5 == null) { return self::renderResultPage($spdf16c9, array('msg' => trans('shop.order.not_found'))); } if ($spaf5db5->status === \App\Order::STATUS_PAID) { $sp8fcdc4 = $spaf5db5->user->qq; if ($spaf5db5->product) { if ($spaf5db5->product->delivery === \App\Product::DELIVERY_MANUAL) { $spbabee5 = trans('shop.order.msg_product_manual_please_wait'); } else { $spbabee5 = trans('shop.order.msg_product_out_of_stock_not_send'); } } else { $spbabee5 = trans('shop.order.msg_product_deleted'); } if ($sp8fcdc4) { $spbabee5 .= '<br><a href="http://wpa.qq.com/msgrd?v=3&uin=' . $sp8fcdc4 . '&site=qq&menu=yes" target="_blank">客服QQ:' . $sp8fcdc4 . '</a>'; } return self::renderResultPage($spdf16c9, array('success' => false, 'title' => trans('shop.order_is_paid'), 'msg' => $spbabee5), array('order' => $spaf5db5)); } elseif ($spaf5db5->status >= \App\Order::STATUS_SUCCESS) { return self::showOrderResult($spdf16c9, $spaf5db5); } return self::renderResultPage($spdf16c9, array('success' => false, 'msg' => $spaf5db5->remark ? trans('shop.order_process_failed_because', array('reason' => $spaf5db5->remark)) : trans('shop.order_process_failed_default')), array('order' => $spaf5db5)); } function renderResultPage(Request $spdf16c9, $spb6456a, $sp5c7064 = array()) { if ($spdf16c9->ajax()) { if (@$spb6456a['success']) { return Response::success($spb6456a['data']); } else { return Response::fail('error', $spb6456a['msg']); } } else { return view('pay.result', array_merge(array('result' => $spb6456a, 'data' => $sp5c7064), $sp5c7064)); } } function shipOrder($spdf16c9, $spf922d7, $spd1ad1b, $sp841c55) { $spaf5db5 = \App\Order::whereOrderNo($spf922d7)->first(); if ($spaf5db5 === null) { Log::error('shipOrder: No query results for model [App\\Order:' . $spf922d7 . ',trade_no:' . $sp841c55 . ',amount:' . $spd1ad1b . ']. die(\'success\');'); die('success'); } if ($spaf5db5->paid > $spd1ad1b) { Log::alert('shipOrder, price may error, order_no:' . $spf922d7 . ', paid:' . $spaf5db5->paid . ', $amount get:' . $spd1ad1b); $spaf5db5->remark = '支付金额(' . sprintf('%0.2f', $spd1ad1b / 100) . ') 小于 订单金额(' . sprintf('%0.2f', $spaf5db5->paid / 100) . ')'; $spaf5db5->save(); throw new \Exception($spaf5db5->remark); } $sp94204a = null; if ($spaf5db5->status === \App\Order::STATUS_UNPAY) { Log::debug('shipOrder.first_process:' . $spf922d7); if (FundHelper::orderSuccess($spaf5db5->id, function ($sp966fe0) use($sp841c55, &$spaf5db5, &$sp94204a) { $spaf5db5 = $sp966fe0; if ($spaf5db5->status !== \App\Order::STATUS_UNPAY) { \Log::debug('Shop.Pay.shipOrder: .first_process:' . $spaf5db5->order_no . ' already processed! #2'); return false; } $sp94204a = $spaf5db5->product()->lockForUpdate()->firstOrFail(); $spaf5db5->pay_trade_no = $sp841c55; $spaf5db5->paid_at = Carbon::now(); if ($sp94204a->delivery === \App\Product::DELIVERY_MANUAL) { $spaf5db5->status = \App\Order::STATUS_PAID; $spaf5db5->send_status = \App\Order::SEND_STATUS_CARD_UN; $spaf5db5->saveOrFail(); return true; } if ($sp94204a->delivery === \App\Product::DELIVERY_API) { $spab3b23 = $sp94204a->createApiCards($spaf5db5); } else { $spab3b23 = Card::where('product_id', $spaf5db5->product_id)->whereRaw('`count_sold`<`count_all`')->take($spaf5db5->count)->lockForUpdate()->get(); } $sp80387e = false; if (count($spab3b23) === $spaf5db5->count) { $sp80387e = true; } else { if (count($spab3b23)) { foreach ($spab3b23 as $spe82347) { if ($spe82347->type === \App\Card::TYPE_REPEAT && $spe82347->count >= $spaf5db5->count) { $spab3b23 = array($spe82347); $sp80387e = true; break; } } } } if ($sp80387e === false) { Log::alert('Shop.Pay.shipOrder: 订单:' . $spaf5db5->order_no . ', 购买数量:' . $spaf5db5->count . ', 卡数量:' . count($spab3b23) . ' 卡密不足(已支付 未发货)'); $spaf5db5->status = \App\Order::STATUS_PAID; $spaf5db5->saveOrFail(); return true; } else { $spc185a3 = array(); foreach ($spab3b23 as $spe82347) { $spc185a3[] = $spe82347->id; } $spaf5db5->cards()->attach($spc185a3); if (count($spab3b23) === 1 && $spab3b23[0]->type === \App\Card::TYPE_REPEAT) { \App\Card::where('id', $spc185a3[0])->update(array('status' => \App\Card::STATUS_SOLD, 'count_sold' => DB::raw('`count_sold`+' . $spaf5db5->count))); } else { \App\Card::whereIn('id', $spc185a3)->update(array('status' => \App\Card::STATUS_SOLD, 'count_sold' => DB::raw('`count_sold`+1'))); } $spaf5db5->status = \App\Order::STATUS_SUCCESS; $spaf5db5->saveOrFail(); $sp94204a->count_sold += $spaf5db5->count; $sp94204a->saveOrFail(); return FundHelper::ACTION_CONTINUE; } })) { if ($sp94204a->count_warn > 0 && $sp94204a->count < $sp94204a->count_warn) { try { Mail::to($spaf5db5->user->email)->Queue(new ProductCountWarn($sp94204a, $sp94204a->count)); } catch (\Throwable $sp54a0c6) { LogHelper::setLogFile('mail'); Log::error('shipOrder.count_warn error', array('product_id' => $spaf5db5->product_id, 'email' => $spaf5db5->user->email, 'exception' => $sp54a0c6->getMessage())); LogHelper::setLogFile('card'); } } if (System::_getInt('mail_send_order')) { $sp94195a = @json_decode($spaf5db5->contact_ext, true)['_mail']; if ($sp94195a) { $spaf5db5->sendEmail($sp94195a); } } if ($spaf5db5->status === \App\Order::STATUS_SUCCESS && System::_getInt('sms_send_order')) { $sp23d898 = @json_decode($spaf5db5->contact_ext, true)['_mobile']; if ($sp23d898) { $spaf5db5->sendSms($sp23d898); } } } else { if ($spaf5db5->status !== \App\Order::STATUS_UNPAY) { } else { Log::error('Pay.shipOrder.orderSuccess Failed.'); return FALSE; } } } else { Log::debug('Shop.Pay.shipOrder: .order_no:' . $spaf5db5->order_no . ' already processed! #1'); } return FALSE; } private function showOrderResult($spdf16c9, $spaf5db5) { return self::renderResultPage($spdf16c9, array('success' => true, 'msg' => $spaf5db5->getSendMessage()), array('card_txt' => join('&#013;&#010;', $spaf5db5->getCardsArray()), 'order' => $spaf5db5, 'product' => $spaf5db5->product)); } }