{{--
    Cookieポリシー（P-18、7.1.1 / 4.9.1 / 4.9.3。工程7-f）。ダミー本文のため冒頭に注記を出す。

    本サイトが使用するCookie（セッション、選択中の館 cinema_slug、CSRF対策、ログイン保持
    remember_web_*、決済画面の Stripe の __stripe_mid・__stripe_sid）を一覧する（4.9.5）。
    `Cinema::SESSION_KEY` と `config/session.php` の値に基づく（セッションの
    有効期間は `lifetime`＝120分、cinema_slug は `CurrentCinemaService` が
    1年（60分×24×365）で保存する）。
--}}
<x-front.page
    :title="__('front.pages.cookie-policy.title')"
    :heading="__('front.pages.cookie-policy.heading')"
    :description="__('front.pages.cookie-policy.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    <section>
        <p>{{ __('front.pages.cookie-policy.lead') }}</p>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full border-collapse border border-stone-300 text-left">
                <thead>
                    <tr class="bg-stone-100">
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.cookie-policy.column_name') }}</th>
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.cookie-policy.column_purpose') }}</th>
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.cookie-policy.column_duration') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (__('front.pages.cookie-policy.cookies') as $cookie)
                        <tr>
                            <td class="border border-stone-300 p-2">{{ $cookie['name'] }}</td>
                            <td class="border border-stone-300 p-2">{{ $cookie['purpose'] }}</td>
                            <td class="border border-stone-300 p-2">{{ $cookie['duration'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.cookie-policy.consent_heading') }}</x-front.section-heading>
        <p class="mt-4">{{ __('front.pages.cookie-policy.consent_note') }}</p>
    </section>
</x-front.page>
