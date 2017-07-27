<?php

namespace SlimKit\PlusQuestion\API2\Controllers;

use Illuminate\Http\Request;
use SlimKit\PlusQuestion\Models\Topic as TopicModel;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;

class TopicUserController extends Controller
{
    /**
     * Get all topics of the authenticated user.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function index(Request $request, ResponseFactoryContract $response)
    {
        $user = $this->resolveUser($request->user());
        $limit = min(50, max(1, intval($request->query('limit', 20))));
        $after = $request->query('after', false);
        $type = in_array(($type = $request->query('type', 'follow')), ['follow', 'expert']) ? $type : 'follow';
        $methodMap = [
            'follow' => 'questionTopics',
            'expert' => 'belongTopics',
        ];

        $topics = $user->{$methodMap[$type]}()
            ->when($after, function ($query) use ($after) {
                return $query->where('id', '<', $after);
            })
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return $response->json($topics, 200);
    }

    /**
     * Follow a topic.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     * @param \SlimKit\PlusQuestion\Models\Topic $topic
     * @return mixed
     * @author Seven Du <shiweidu@outlook.com>
     */
    public function store(Request $request, ResponseFactoryContract $response, TopicModel $topic)
    {
        $user = $this->resolveUser(
            $request->user()
        );

        if ($user->questionTopics()->newPivotStatementForId($topic->id)->first()) {
            return $response->json(['message' => ['已关注了该话题，请勿重复操作']], 422);
        }

        $user->questionTopics()->attach($topic);

        return $response->json(['message' => ['操作成功']], 201);
    }
}
