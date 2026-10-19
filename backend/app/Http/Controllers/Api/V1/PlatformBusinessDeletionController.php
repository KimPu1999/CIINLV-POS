<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Platform\DeleteBusinessRequest;
use App\Http\Requests\Platform\ReauthenticateDeletionRequest;
use App\Models\Tenant;
use App\Services\BusinessDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
class PlatformBusinessDeletionController
{
    public function preview(Tenant $business, BusinessDeletionService $service): JsonResponse { Gate::authorize('manage', $business); return response()->json(['success'=>true,'data'=>$service->preview($business)]); }
    public function reauthenticate(ReauthenticateDeletionRequest $request, BusinessDeletionService $service): JsonResponse { $service->reauthenticate($request->user(), $request->string('password')->toString()); return response()->json(['success'=>true,'message'=>'Recent reauthentication accepted.']); }
    public function destroy(DeleteBusinessRequest $request, Tenant $business, BusinessDeletionService $service): JsonResponse { Gate::authorize('manage', $business); return response()->json(['success'=>true,'data'=>$service->delete($business, $request->user(), $request->string('business_code')->toString(), $request->string('preview_token')->toString())]); }
}
