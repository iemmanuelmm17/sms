<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default outbound number per authorized sender: blank-subject
     * email→SMS sends from this number. Backfilled to the sender's
     * first assigned number (auto-first rule).
     */
    public function up(): void
    {
        Schema::table('email_sms_senders', function (Blueprint $table) {
            $table->string('default_number', 32)->nullable()->after('numbers');
        });
        foreach (DB::table('email_sms_senders')->get(['id', 'numbers']) as $row) {
            $nums = json_decode((string) $row->numbers, true);
            if (!is_array($nums)) continue;
            foreach ($nums as $n) {
                $d = preg_replace('/\D/', '', (string) $n);
                if (strlen($d) >= 7 && strlen($d) <= 15) {
                    DB::table('email_sms_senders')->where('id', $row->id)->update(['default_number' => $d]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('email_sms_senders', function (Blueprint $table) {
            $table->dropColumn('default_number');
        });
    }
};
