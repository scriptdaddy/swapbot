@extends('emails.base.base-bot-image-html')

@section('subheaderTitle')
<h4>Hello Again!</h4>
<p>&nbsp;</p>
@stop


@section('main')
<?php $receipt = $swap['receipt']; $receipt_type = (isset($receipt['type']) ? $receipt['type'] : null); ?>


<p>The tokens you recently purchased from {!! $botLink !!} have been delivered.</p>

<p>When you’re ready, log into your wallet to use, send or redeem them as you see fit.</p>

<p>To recap your order, you sent {!! $botLink !!} {{ $currency($inQty) }} {{ $inAsset }} and we’ve just sent you {{ $currency($outQty) }} {{ $outAsset }}{{ $hasChange ? " along with ".$currency($swap['receipt']['changeOut'])." {$inAsset} in change" : ''}}.</p>


<p style="height: 12px;">&nbsp;</p>
<hr />
<p style="height: 2px;">&nbsp;</p>
<h4>Your Swap Receipt</h4>
<p>&nbsp;</p>

<p><strong>Status</strong></p>
<p>{{ $fmt->formatState($swap['state']) }}</p>

<p><strong>Deposit Received</strong></p>
<p>{{ $fmt->formatDate($swap['createdAt']) }}</p>

<p><strong>Tokens Delivered</strong></p>
<p>{{ $fmt->formatDate($swap['completedAt']) }}</p>

<p><strong>Amount</strong></p>
<p>
@if (($receipt_type == 'refund'))

    Received {{ $currency($receipt['quantityIn']) }} {{ $receipt['assetIn'] }}{{ $fmt->fiatSuffix($strategy, $receipt['quantityIn'], $receipt['assetIn'], isset($receipt['conversionRate']) ? $receipt['conversionRate'] : null) }} and refunded
    {{ $currency($receipt['quantityOut']) }} {{ $receipt['assetOut'] }}{{ $fmt->fiatSuffix($strategy, $receipt['quantityOut'], $receipt['assetOut'], isset($receipt['conversionRate']) ? $receipt['conversionRate'] : null) }}

@elseif (isset($receipt['assetIn']) AND isset($receipt['assetOut']))

    {{ $currency($receipt['quantityIn']) }} {{ $receipt['assetIn'] }}{{ $fmt->fiatSuffix($strategy, $receipt['quantityIn'], $receipt['assetIn'], isset($receipt['conversionRate']) ? $receipt['conversionRate'] : null) }}
    →
    {{ $currency($receipt['quantityOut']) }} {{ $receipt['assetOut'] }}{{ $fmt->fiatSuffix($strategy, $receipt['quantityOut'], $receipt['assetOut'], isset($receipt['conversionRate']) ? $receipt['conversionRate'] : null) }}

@else
    <span class="none">none</span>
@endif
</p>

@if (isset($receipt['changeOut']) AND $receipt['changeOut'] > 0)
<p><strong>Change</strong></p>
<p>
{{ $currency($receipt['changeOut']) }} {{ isset($receipt['changeOutAsset']) ? $receipt['changeOutAsset'] : 'BTC' }} in change
</p>
@endif

<p><strong>Recipient's address</strong></p>
<p>
    @if (isset($receipt['destination']))
        <a href="{{ $fmt->formatAddressHref($receipt['destination']) }}" target="_blank">{{ $receipt['destination'] }}</a>
    @else
        <span class="none">none</span>
    @endif
</p>

<p><strong>Swapbot's address</strong></p>
<p>
    <a href="{{ $fmt->formatAddressHref($bot['address']) }}" target="_blank">{{ $bot['address'] }}</a>
</p>

<p><strong>Incoming Transaction ID</strong></p>
<p>
    @if (isset($receipt['txidIn']))
        <a href="{{ $fmt->formatBlockchainHref($receipt['txidIn'], $receipt['assetIn']) }}" target="_blank">{{ $receipt['txidIn'] }}</a>
    @else
        <span class="none">none</span>
    @endif
</p>

<p><strong>Outgoing Transaction ID</strong></p>
<p>
    @if (isset($receipt['txidOut']))
        <a href="{{ $fmt->formatBlockchainHref($receipt['txidOut'], $receipt['assetOut']) }}" target="_blank">{{ $receipt['txidOut'] }}</a>
    @else
        <span class="none">none</span>
    @endif
</p>


<p style="height: 12px;">&nbsp;</p>
<hr />
<p style="height: 2px;">&nbsp;</p>
<h4>What Happens Next?</h4>
<p>&nbsp;</p>

<p>That’s it!  You can make a new purchase if you’d like.  And thanks for using Swapbot, if you’d like to create your own automated multi-token vending machine in just a few minutes, visit {{ $host }}.</p>

<p>If you have any questions or comments about your experience please email the team@tokenly.com.</p>


@stop