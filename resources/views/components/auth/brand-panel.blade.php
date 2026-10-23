{{--
    The brand panel shared by signed-out screens (DESIGN.md §7.1).
    The gradient and the white logo come from tokens and the knockout asset. The words are the ones a shared
    link shows (App\Appearance\Share), so the sign-in page and a preview never disagree.
--}}
<aside {{ $attributes->class('surface-brand relative isolate flex flex-col overflow-hidden px-6 pt-6 pb-8 sm:px-10 lg:min-h-dvh lg:px-14 lg:py-12') }}>
    <x-logo variant="knockout" class="h-8 self-start sm:h-9 lg:h-10" />

    <div class="mt-6 max-w-md lg:mt-auto lg:mb-auto">
        <p class="text-2xl font-semibold tracking-tight text-balance sm:text-3xl lg:text-4xl">{{ \App\Appearance\Share::TAGLINE }}</p>
        <p class="mt-3 hidden text-base text-fg-muted sm:block lg:text-lg">
            {{ \App\Appearance\Share::DESCRIPTION }}
        </p>
    </div>

    <x-logo variant="knockout" mark large class="pointer-events-none absolute -right-16 -bottom-20 -z-10 hidden h-96 opacity-10 lg:block" alt="" aria-hidden="true" />
</aside>
