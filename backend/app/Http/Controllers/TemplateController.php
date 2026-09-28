<?php

namespace App\Http\Controllers;

use App\Events\DataChanged;
use App\Models\Template;
use App\Http\Controllers\Concerns\ResolvesActor;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    use ResolvesActor;
    protected function scope(Request $r): array
    {
        $a = $this->actor($r);
        return [$a['domain'], $a['user']];
    }

    /** GET /api/templates */
    public function index(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $list = \Illuminate\Support\Facades\Cache::remember(Template::listKey($domain, $user), 120, fn() =>
            Template::where('domain', $domain)
                ->where(fn($q) => $q->where('user', $user)->orWhere('shared', true))
                ->orderBy('name')->get()->toArray());
        return response()->json($list);
    }

    /** POST /api/templates { name, body, shared? } — supports $FirstName, $LastName, $CompanyName, $AgentName. */
    public function store(Request $request)
    {
        [$domain, $user] = $this->scope($request);
        $actor = $this->actor($request);
        $cbKey = $actor['role'] === 'agent' ? 'agent:' . $actor['agent_id'] : $user;
        $cbName = $actor['display_name'] ?? null;
        $data = $request->validate([
            'name'    => 'required|string|max:120',
            'body'    => 'required|string|max:2000',
            'shared'  => 'sometimes|boolean',
            'keyword' => 'sometimes|nullable|string|max:60',
        ]);
        if (isset($data['keyword'])) $data['keyword'] = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($data['keyword'], '/'))) ?: null;
        $t = Template::create($data + ['domain' => $domain, 'user' => $user,
            'created_by' => $cbKey, 'created_by_name' => $cbName,
            'updated_by' => $cbKey, 'updated_by_name' => $cbName]);
        $this->audit($request, 'template.created', ['template_id' => $t->id, 'name' => $t->name]);
        Template::bustList($domain);
        DataChanged::send($domain, $user, 'templates', 'saved', $t->id);
        return response()->json($t, 201);
    }

    /** PUT /api/templates/{id} */
    public function update(Request $request, Template $template)
    {
        $this->assertOwn($request, $template);
        [$domain, $user] = $this->scope($request);
        abort_unless($template->domain === $domain, 403);
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'body' => 'sometimes|string|max:2000',
            'shared' => 'sometimes|boolean',
            'keyword' => 'sometimes|nullable|string|max:60',
        ]);
        if (isset($data['keyword'])) $data['keyword'] = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($data['keyword'], '/'))) ?: null;
        $actor = $this->actor($request);
        $data['updated_by'] = $actor['role'] === 'agent' ? 'agent:' . $actor['agent_id'] : $user;
        $data['updated_by_name'] = $actor['display_name'] ?? null;
        $template->update($data);
        $this->audit($request, 'template.updated', ['template_id' => $template->id, 'name' => $template->name, 'keys' => array_values(array_diff(array_keys($data), ['updated_by', 'updated_by_name']))]);
        Template::bustList($domain);
        DataChanged::send($domain, $user, 'templates', 'saved', $template->id);
        return response()->json($template);
    }

    /** DELETE /api/templates/{id} */
    public function destroy(Request $request, Template $template)
    {
        $this->assertOwn($request, $template);
        [$domain, $user] = $this->scope($request);
        abort_unless($template->domain === $domain, 403);
        $id = $template->id; $nm = $template->name;
        $template->delete();
        $this->audit($request, 'template.deleted', ['template_id' => $id, 'name' => $nm]);
        Template::bustList($domain);
        DataChanged::send($domain, $user, 'templates', 'deleted', $id);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/templates/{id}/resolve { first_name?, last_name?, company?, ... }
     * Replaces {{placeholders}} with provided values.
     */
    /** Agents own only templates they created (created_by = 'agent:{id}'). Admins own all. */
    protected function assertOwn(Request $request, Template $template): void
    {
        $actor = $this->actor($request);
        if ($actor['role'] === 'agent' && $template->created_by !== 'agent:' . $actor['agent_id']) {
            abort(403, 'You can only edit templates you created.');
        }
    }

    public function resolve(Request $request, Template $template)
    {
        [$domain] = $this->scope($request);
        abort_unless($template->domain === $domain, 403);
        $body = $template->body;
        foreach ($request->all() as $k => $v) {
            if (is_scalar($v)) $body = str_replace('{{' . $k . '}}', (string) $v, $body);
        }
        return response()->json(['body' => $body]);
    }
}
