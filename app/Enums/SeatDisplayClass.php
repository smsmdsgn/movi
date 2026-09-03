<?php

namespace App\Enums;

/**
 * 座席種別の座席表での表現区分（6.3.2）。1マス1席の前提のもと、
 * 装飾（アイコン・強調表示）の出し分けにのみ用いる。
 */
enum SeatDisplayClass: string
{
    case Standard = 'standard';
    case Wheelchair = 'wheelchair';
    case Executive = 'executive';
}
