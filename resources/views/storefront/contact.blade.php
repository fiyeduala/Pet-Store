<x-layouts.storefront title="Contact us">
    <div class="container-page max-w-4xl py-10 lg:py-16">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_280px]">
            <div>
                <h1 class="text-3xl lg:text-4xl">Contact us</h1>
                <p class="mt-2 text-ink-500">Questions about an order, a product, or a return? Send us a message.</p>

                @if (session('status'))
                    <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
                @endif

                <form method="POST" action="{{ route('contact.store') }}" class="mt-6 space-y-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="name" class="field-label">Your name</label>
                            <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" class="field-input" required>
                            @error('name') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="contact_email" class="field-label">Email</label>
                            <input id="contact_email" name="email" type="email" value="{{ old('email') }}"
                                   autocomplete="email" class="field-input" required>
                            @error('email') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label for="order_number" class="field-label">Order number <span class="text-ink-400">(optional)</span></label>
                        <input id="order_number" name="order_number" value="{{ old('order_number') }}" class="field-input">
                    </div>
                    <div>
                        <label for="subject" class="field-label">Subject</label>
                        <input id="subject" name="subject" value="{{ old('subject') }}" class="field-input" required>
                        @error('subject') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="message" class="field-label">Message</label>
                        <textarea id="message" name="message" rows="6" class="field-input" required>{{ old('message') }}</textarea>
                        @error('message') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Send message</button>
                </form>
            </div>

            <aside class="space-y-4">
                <div class="card p-5 text-sm">
                    <h2 class="mb-2 text-base">Get in touch</h2>
                    @if (branding('support_email'))
                        <p><a href="mailto:{{ branding('support_email') }}" class="underline">{{ branding('support_email') }}</a></p>
                    @endif
                    @if (branding('support_phone'))
                        <p class="mt-1">{{ branding('support_phone') }}</p>
                    @endif
                    @if (branding('support_hours'))
                        <p class="mt-2 text-ink-500">{{ branding('support_hours') }}</p>
                    @endif
                </div>
                <div class="card p-5 text-sm">
                    <h2 class="mb-2 text-base">Order questions</h2>
                    <p class="text-ink-500">Already ordered? You can <a href="{{ route('orders.track') }}" class="underline">track your order</a> without an account.</p>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.storefront>
