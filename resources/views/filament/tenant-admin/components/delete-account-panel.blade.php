<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.
    </p>

    <flux:button
        wire:click="mountAction('deleteAccount')"
        variant="primary"
        color="danger"
    >
        Delete Account
    </flux:button>
</div>
