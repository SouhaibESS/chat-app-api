<?php

namespace App\Http\Controllers;

use App\Conversation;
use App\Events\messagesSeenEvent;
use App\Events\newMessageEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\Conversation as ConversationResource;
use App\Http\Resources\ConversationOnly as ConversationOnlyResource;
use App\Http\Resources\Message as MessageResource;
use App\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Validator;

class ConversationController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth:api');
    }


    public function index()
    {
        $user = Auth::user();

        $conversations = $user->conversations()->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'conversations' => ConversationOnlyResource::collection($conversations)
        ], 200);
    }

    public function show(Conversation $conversation)
    {

        if (Gate::allows('has-conversation', $conversation))
            return response()->json([
                'success' => true,
                'conversation' => new ConversationResource($conversation)
            ], 200);

        return response()->json([
            'success' => false,
            'message' => 'permission denied'
        ]);
    }

    public function newMessage(Conversation $conversation, Request $request)
    {
        $credentials = request()->json()->all();
        $rules = ['message' => 'required|min:3|max:500'];

        $validator = Validator::make($credentials, $rules);

        if ($validator->fails())
            return response()->json([
                'success' => false,
                'message' => $validator->errors()
            ]);

        // add the message to the conversation
        $message = $conversation->messages()
            ->create([
                'message' => $request->json()->get('message'),
                'user_id' => Auth::id(),
                'seen' => 0
            ]);

        // update conversation  
        $now = Carbon::now();
        $conversation->update(['updated_at' => $now->toDateTimeString()]);

        // sends the newMessage notif to the other user
        broadcast(new newMessageEvent($message))->toOthers();

        return response()->json([
            'sucess' => true,
            'message' => new MessageResource($message)
        ]);
    }

    public function messagesSeen(Conversation $conversation)
    {
        $messages = $conversation->messages()->where('seen', 0)->get();

        if ($messages)
            foreach ($messages as $message) {
                if ($message->user_id != Auth::id())
                    $message->update([
                        'seen' => 1
                    ]);
            }

        // notify the sender that he's messages are seen
        broadcast(new messagesSeenEvent($conversation))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'messages seen',
        ]);
    }
}
