<?php

namespace App\Actions\DealerApplications;

use App\Models\Dealer;
use App\Models\DealerApplication;
use App\Models\DealerApplicationEvent;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeDealerApplication
{
    public function execute(DealerApplication $application, User $actor, ?string $remark = null): DealerApplication
    {
        $remark = filled($remark) ? trim($remark) : null;

        return DB::transaction(function () use ($application, $actor, $remark): DealerApplication {
            /** @var DealerApplication $locked */
            $locked = DealerApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DealerApplication::STATUS_APPROVED && $locked->dealer_id !== null) {
                if ($locked->party_id === null && $locked->dealer !== null) {
                    $party = Party::firstOrCreateFromDealer($locked->dealer);
                    $locked->update(['party_id' => $party->id]);
                }

                return $locked->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer', 'party'])
                    ?? $locked;
            }

            if ($locked->status !== DealerApplication::STATUS_PENDING_ADMIN) {
                throw ValidationException::withMessages([
                    'status' => 'Dealer code can only be generated after Admin final approval.',
                ]);
            }

            $dealer = Dealer::query()->create([
                'firm_name' => $locked->firm_name,
                'owner_name' => $locked->owner_name,
                'mobile' => $locked->mobile,
                'gst_no' => $locked->gst_no,
                'address' => $locked->address,
                'village' => $locked->village,
                'taluka' => $locked->taluka,
                'district' => $locked->district,
                'state' => $locked->state,
                'latitude' => $locked->latitude,
                'longitude' => $locked->longitude,
                'dealer_type' => 'Retailer',
                'status' => true,
                'assigned_employee_id' => $locked->employee_id,
            ]);

            $party = Party::firstOrCreateFromDealer($dealer);

            $locked->update([
                'status' => DealerApplication::STATUS_APPROVED,
                'admin_id' => $actor->id,
                'admin_name' => $actor->name,
                'admin_approved_at' => now(),
                'admin_remark' => $remark,
                'dealer_id' => $dealer->id,
                'party_id' => $party->id,
                'last_action' => DealerApplicationEvent::ADMIN_APPROVED,
                'last_action_by' => $actor->id,
                'last_action_by_name' => $actor->name,
                'last_action_at' => now(),
                'last_action_remark' => $remark,
            ]);

            $locked->recordEvent(DealerApplicationEvent::ADMIN_APPROVED, $actor, $remark);
            $locked->recordEvent(
                DealerApplicationEvent::DEALER_CODE_GENERATED,
                $actor,
                null,
                ['dealer_code' => $dealer->dealer_code, 'dealer_id' => $dealer->id],
            );
            $locked->recordEvent(
                DealerApplicationEvent::PARTY_CREATED,
                $actor,
                null,
                ['party_id' => $party->id, 'party_name' => $party->party_name],
            );

            return $locked->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer', 'party'])
                ?? $locked;
        });
    }
}
