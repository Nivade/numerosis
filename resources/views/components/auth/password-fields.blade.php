{{--
    The password + confirmation pair, identical on register and reset. Kept in
    one place so `autocomplete` and `viewable` cannot drift between the two
    screens Fortify posts them from.
--}}
<flux:input
    name="password"
    :label="__('Password')"
    type="password"
    required
    autocomplete="new-password"
    :placeholder="__('Password')"
    viewable
/>

<flux:input
    name="password_confirmation"
    :label="__('Confirm password')"
    type="password"
    required
    autocomplete="new-password"
    :placeholder="__('Confirm password')"
    viewable
/>
