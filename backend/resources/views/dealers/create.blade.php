<x-app-layout>

<div class="container mt-4">

    <div class="card shadow">

        <div class="card-header">
            <h4>Add Dealer</h4>
        </div>

        <div class="card-body">

            <form method="POST" action="{{ route('dealers.store') }}">

                @csrf

                <div class="mb-3">
                    <label class="form-label">Firm Name <span class="text-danger">*</span></label>
                    <input type="text" name="firm_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Assigned Employee <span class="text-danger">*</span></label>
                    <input type="number" name="assigned_employee_id" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Owner Name</label>
                    <input type="text" name="owner_name" class="form-control">
                </div>

                <div class="mb-3">
                    <label class="form-label">Mobile <span class="text-danger">*</span></label>
                    <input type="text" name="mobile" class="form-control" required maxlength="10">
                </div>

                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="3"></textarea>
                </div>

                <div class="row g-2">

                    <div class="col-md-3">
                        <label class="form-label">State <span class="text-danger">*</span></label>
                        <input type="text" name="state" class="form-control" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">District <span class="text-danger">*</span></label>
                        <input type="text" name="district" class="form-control" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Taluka <span class="text-danger">*</span></label>
                        <input type="text" name="taluka" class="form-control" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Village <span class="text-danger">*</span></label>
                        <input type="text" name="village" class="form-control" required>
                    </div>

                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" class="form-control" maxlength="6">
                    </div>
                </div>

                <br>

                <button class="btn btn-success">
                    Save Dealer
                </button>

            </form>

        </div>

    </div>

</div>

</x-app-layout>
