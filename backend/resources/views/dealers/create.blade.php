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
                    <label class="form-label">Firm Name</label>
                    <input type="text" name="firm_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Owner Name</label>
                    <input type="text" name="owner_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Mobile</label>
                    <input type="text" name="mobile" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="3"></textarea>
                </div>

                <div class="row">

                    <div class="col-md-3">
                        <input type="text" name="state" class="form-control" placeholder="State">
                    </div>

                    <div class="col-md-3">
                        <input type="text" name="district" class="form-control" placeholder="District">
                    </div>

                    <div class="col-md-3">
                        <input type="text" name="taluka" class="form-control" placeholder="Taluka">
                    </div>

                    <div class="col-md-3">
                        <input type="text" name="pincode" class="form-control" placeholder="Pincode">
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