<footer class="bg-brand text-white">
    <div class="grid grid-cols-1 gap-6 px-4 py-8 md:grid-cols-3">
        <div>
            <p class="font-bold">{{ __('front.footer.site_info') }}</p>
            <ul class="mt-2 space-y-1 text-sm">
                <li><a href="{{ route('front.company.index') }}">{{ __('front.footer.company') }}</a></li>
                <li><a href="{{ route('front.recruit.index') }}">{{ __('front.footer.recruit') }}</a></li>
                <li><a href="{{ route('front.sitemap.index') }}">{{ __('front.footer.sitemap') }}</a></li>
            </ul>
        </div>

        <div>
            <p class="font-bold">{{ __('front.footer.legal_group') }}</p>
            <ul class="mt-2 space-y-1 text-sm">
                <li><a href="{{ route('front.terms.index') }}">{{ __('front.footer.terms') }}</a></li>
                <li><a href="{{ route('front.privacy.index') }}">{{ __('front.footer.privacy') }}</a></li>
                <li><a href="{{ route('front.cookie-policy.index') }}">{{ __('front.footer.cookie_policy') }}</a></li>
                <li><a href="{{ route('front.legal.index') }}">{{ __('front.footer.legal') }}</a></li>
            </ul>
        </div>

        <div>
            <p class="font-bold">{{ __('front.footer.support') }}</p>
            <ul class="mt-2 space-y-1 text-sm">
                <li><a href="{{ route('front.faq.index') }}">{{ __('front.footer.faq') }}</a></li>
                <li><a href="{{ route('front.contact.index') }}">{{ __('front.footer.contact') }}</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-white/20 px-4 py-4 text-xs text-white/70">
        <p>{{ __('front.footer.tmdb_credit') }}</p>
        <p class="mt-1">{{ __('front.footer.copyright', ['year' => now()->year]) }}</p>
    </div>
</footer>
