<section class="w-full">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-zinc-50 dark:bg-zinc-950">
        <div class="max-w-4xl w-full space-y-12">
            <!-- Header -->
            <div class="text-center space-y-4">
                <div class="mx-auto h-16 w-16 rounded-2xl bg-linear-to-tr from-blue-600 to-indigo-600 flex items-center justify-center mb-6 shadow-xl shadow-blue-500/20 rotate-3 hover:rotate-0 transition-transform duration-500">
                    <flux:icon name="building-office-2" class="h-8 w-8 text-white" />
                </div>
                <h1 class="text-4xl md:text-5xl font-black tracking-tight text-zinc-900 dark:text-white">
                    Start Your <span class="text-transparent bg-clip-text bg-linear-to-r from-blue-600 to-indigo-600">Journey</span>
                </h1>
                <p class="text-lg text-zinc-500 dark:text-zinc-400 max-w-md mx-auto leading-relaxed">
                    Create your company workspace and join thousands of successful businesses
                </p>
            </div>
            <!-- Progress Steps -->
            <x-ui.stepper
                :steps="['Company Info', 'Technical Setup', 'Plan', 'Payment']"
                :current="$this->getCurrentStepNumber()"
            />


            <!-- Alert Messages -->
            <x-ui.alert closable />

            <!-- Registration Form -->
            <div class="bg-white dark:bg-zinc-900 rounded-3xl shadow-2xl shadow-zinc-200/50 dark:shadow-none border border-zinc-200 dark:border-zinc-800 p-8 md:p-12 transition-all duration-500">
                <div class="space-y-8" x-transition>
                    @livewire($this->currentStepName, $currentStepState, key($this->currentStepName))
                </div>
            </div>
            <!-- Footer -->
            <div class="text-center mt-8">
                <div class="mt-4 text-xs text-gray-500 dark:text-gray-500">
                    &copy; {{ date('Y') }} Your Company. All rights reserved.
                </div>
            </div>
        </div>
    </div>
</section>

<!-- JavaScript for handling modals -->
<script>
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Close all modals when Escape key is pressed
            document.querySelectorAll('[id^="features-modal-"]').forEach(modal => {
                modal.classList.add('hidden');
            });
        }
    });

    // Close modal when clicking outside of it
    document.addEventListener('click', function(e) {
        document.querySelectorAll('[id^="features-modal-"]').forEach(modal => {
            if (e.target === modal) {
                modal.classList.add('hidden');
            }
        });
    });
</script>
