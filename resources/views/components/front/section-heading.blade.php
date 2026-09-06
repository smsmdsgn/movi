{{--
    セクション見出しのダークブラウンの帯（18.4-2）。
    見出し階層を飛ばさないよう、呼び出し側で `level` を切り替えられるようにする（19.3-7）。
--}}
@props(['level' => 'h2'])
<{{ $level }} class="bg-brand px-4 py-2 text-base font-bold text-white md:text-lg">{{ $slot }}</{{ $level }}>
