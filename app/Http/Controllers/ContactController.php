<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Mail\ContactFormSubmitted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function create(): View
    {
        return view('contact');
    }

    public function store(StoreContactRequest $request): RedirectResponse|Response
    {
        $to = config('app.contact_email');
        if (! is_string($to) || $to === '') {
            abort(503);
        }

        $validated = $request->validated();

        Mail::to($to)->send(new ContactFormSubmitted(
            $validated['name'],
            $validated['email'],
            $validated['message'],
        ));

        return redirect()
            ->route('contact')
            ->with('status', __('Thanks — your message was sent.'));
    }
}
