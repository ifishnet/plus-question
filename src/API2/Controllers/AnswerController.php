<?php

namespace SlimKit\PlusQuestion\API2\Controllers;

use Illuminate\Http\Request;
use Zhiyi\Plus\Concerns\FindMarkdownFileTrait;
use SlimKit\PlusQuestion\Models\Answer as AnswerModel;
use Zhiyi\Plus\Models\WalletCharge as WalletChargeModel;
use SlimKit\PlusQuestion\Models\Question as QuestionModel;
use SlimKit\PlusQuestion\Models\TopicExpertIncome as ExpertIncomeModel;
use SlimKit\PlusQuestion\API2\Requests\UpdateAnswer as UpdateAnswerRequest;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use SlimKit\PlusQuestion\API2\Requests\QuestionAnswer as QuestionAnswerRequest;

class AnswerController extends Controller
{
    use FindMarkdownFileTrait;

    /**
     * Get all answers for question.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     * @param \SlimKit\PlusQuestion\Models\Question $question
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function index(Request $request, ResponseFactoryContract $response, QuestionModel $question)
    {
        $userID = $request->user('api')->id ?? 0;
        $offset = max(0, $request->query('offset', 0));
        $limit = max(1, min(30, $request->query('limit', 20)));
        $orderMap = [
            'time' => 'id',
            'default' => 'likes_count',
        ];
        $orderType = in_array($orderType = $request->query('order_type', 'default'), array_keys($orderMap)) ? $orderType : 'default';

        $answers = $question->answers()
            ->with('user')
            // 如果是自动入账代表一对一，所以查询邀请答案.
            ->when($question->automaticity, function ($query) {
                return $query->where('invited', 0);
            })
            ->where('adoption', 0)
            ->orderBy($orderMap[$orderType], 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return $response->json($answers->map(function (AnswerModel $answer) use ($userID) {
            if ($answer->anonymity && $answer->user_id !== $userID) {
                $answer->addHidden('user');
                $answer->user_id = 0;
            }

            return $answer;
        }))->setStatusCode(200);
    }

    /**
     * Get a signle answer.
     *
     * @param Request $request
     * @param ResponseFactoryContract $response
     * @param AnswerModel $answer
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function show(Request $request, ResponseFactoryContract $response, AnswerModel $answer)
    {
        $userID = $request->user('api')->id ?? 0;
        $answer->load([
            'likes' => function ($query) {
                $query->limit(5);
                $query->orderBy('id', 'desc');
            },
            'likes.user',
            'rewarders' => function ($query) {
                $query->limit(10);
                $query->orderBy('id', 'desc');
            },
            'rewarders.user',
            'question',
            'question.user',
            'user',
        ]);

        $answer->liked = false;
        $answer->collected = false;
        $answer->rewarded = false;
        ($answer->question->look ?? false) && $answer->could = true;
        if ($userID) {
            $answer->liked = (bool) $answer->likes()->where('user_id', $userID)->first();
            $answer->collected = (bool) $answer->collectors()->where('user_id', $userID)->first();
            $answer->rewarded = (bool) $answer->rewarders()->where('user_id', $userID)->first();
        }

        if ($answer->anonymity && $answer->user_id !== $userID) {
            $answer->addHidden('user');
            $answer->user_id = 0;
        }

        // 无法查看.需要围观。
        if ($answer->question &&
            $answer->question->look &&
            $userID !== $answer->user_id &&
            ! $answer->onlookers()->where('id', $userID)->first()
        ) {
            $answer->could = false;
            $answer->body = null;
        }

        if ($answer->question && $answer->question->anonymity && $answer->question->user_id !== $userID) {
            $answer->question->addHidden('user');
            $answer->question->user_id = 0;
        }

        $answer->increment('views_count', 1);

        return $response->json($answer, 200);
    }

    /**
     * Append answer to question.
     *
     * @param \SlimKit\PlusQuestion\API2\Requests\QuestionAnswer $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     * @param \SlimKit\PlusQuestion\Models\Answer $answer
     * @param \SlimKit\PlusQuestion\Models\Question $question
     * @param \SlimKit\PlusQuestion\Models\Expert $expert
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function store(QuestionAnswerRequest $request,
                          ResponseFactoryContract $response,
                          AnswerModel $answer,
                          QuestionModel $question)
    {
        $user = $request->user();

        $anonymity = $request->input('anonymity') ? 1 : 0;
        $body = $request->input('body');
        $images = $this->findMarkdownImageNotWithModels($body);

        $answer->question_id = $question->id;
        $answer->user_id = $user->id;
        $answer->body = $body;
        $answer->anonymity = $anonymity;

        // 只有存在邀请列表并且没有参与过回答才可被指为邀请回答，否则为普通回答。
        $answer->invited = in_array($user->id, $question->invitations->pluck('id')->toArray()) && ! $question->answers()
            ->where('invited', 1)
            ->where('user_id', $user->id)
            ->first();

        $question->getConnection()->transaction(function () use ($question, $answer, $images, $user) {

            // Save Answer.
            $question->answers()->save($answer);

            // Count
            $question->increment('answers_count', 1);
            $user->extra()->firstOrCreate([])->increment('answers_count', 1);

            // Update images.
            $images->each(function ($image) use ($answer) {
                $image->channel = 'question-answers:images';
                $image->raw = $answer->id;
                $image->save();
            });

            // Automaticity ?
            if ($question->automaticity && $answer->invited) {
                $user->wallet()->increment('balance', $question->amount);

                $charge = new WalletChargeModel();
                $charge->user_id = $user->id;
                $charge->channel = 'user';
                $charge->account = $question->id;
                $charge->action = 1;
                $charge->amount = $question->amount;
                $charge->subject = trans('plus-question::answers.charges.invited.subject');
                $charge->body = trans('plus-question::answers.charges.invited.body', ['body' => $question->subject]);
                $charge->status = 1;

                $user->walletCharges()->save($charge);

                $question->load('topics.experts');
                // get all expert of all the topics belongs to the question.
                $allexpert = $question->topics->map(function ($topic) {
                    return $topic->experts->map(function ($expert) {
                        return $expert->id;
                    });
                })->flatten()->toArray();

                // is the one of experts?
                if (in_array($user->id, $allexpert)) {
                    $income = new ExpertIncomeModel();
                    $income->charge_id = $charge->id;
                    $income->user_id = $user->id;
                    $income->amount = $charge->amount;
                    $income->type = 'answer';

                    $income->save();
                }
            }
        });

        $message = trans(
            $answer->invited
                ? 'plus-question::answers.notifications.invited'
                : 'plus-question::answers.notifications.answer',
            ['user' => $user->name]
        );
        $question->user->sendNotifyMessage('question:answer', $message, [
            'question' => $question,
            'answer' => $answer,
            'user' => $user,
        ]);

        return $response->json([
            'message' => [trans('plus-question::messages.success')],
            'answer' => $answer,
        ], 201);
    }

    /**
     * Update a answer.
     *
     * @param \SlimKit\PlusQuestion\API2\Requests\UpdateAnswer $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     * @param \SlimKit\PlusQuestion\Models\Answer $answer
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function update(UpdateAnswerRequest $request,
                           ResponseFactoryContract $response,
                           AnswerModel $answer)
    {
        $user = $request->user();

        if ($user->id !== $answer->user_id) {
            return $response->json(['message' => [trans('plus-question::answers.not-own')]], 403);
        } elseif ($answer->adoption) {
            return $response->json(['message' => [trans('plus-question::answers.adopted')]], 422);
        }

        $anonymity = $request->input('anonymity', $answer->anonymity) ? 1 : 0;
        $body = $request->input('body', $answer->body) ?: '';
        $images = $this->findMarkdownImageNotWithModels($body);

        $answer->body = $body;
        $answer->anonymity = $anonymity;

        $answer->getConnection()->transaction(function () use ($answer, $images) {

            // Save answer.
            $answer->save();

            // Update image file with.
            $images->each(function ($image) use ($answer) {
                $image->channel = 'question-answers:images';
                $image->raw = $answer->id;
                $image->save();
            });
        });

        return $response->json(['message' => [trans('plus-question::messages.success')]], 201);
    }

    /**
     * Delete a answer.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     * @param \SlimKit\PlusQuestion\Models\Answer $answer
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function destory(Request $request, ResponseFactoryContract $response, AnswerModel $answer)
    {
        $user = $request->user();

        if ($user->id !== $answer->user_id) {
            return $response->json(['message' => [trans('plus-question::answers.not-own')]], 403);
        } elseif ($answer->adoption) {
            return $response->json(['message' => [trans('plus-question::answers.adopted')]], 422);
        } elseif ($answer->invited) {
            return $response->json(['message' => [trans('plus-question::answer.delete.invited')]], 422);
        }

        $answer->delete();

        return $response->make('', 204);
    }
}
