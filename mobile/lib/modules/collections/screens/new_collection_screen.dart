import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/api/dealer_api.dart';
import '../../orders/models/order_dealer.dart';
import '../api/collection_api.dart';

class NewCollectionScreen extends StatefulWidget {
  const NewCollectionScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<NewCollectionScreen> createState() => _NewCollectionScreenState();
}

class _NewCollectionScreenState extends State<NewCollectionScreen> {
  final _amountController = TextEditingController();
  final _remarksController = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  OrderDealer? _selectedDealer;
  DateTime _collectionDate = DateTime.now();
  String? _photoPath;
  bool _submitting = false;
  late Future<List<OrderDealer>> _dealersFuture;

  @override
  void initState() {
    super.initState();
    _dealersFuture = DealerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).list();
  }

  @override
  void dispose() {
    _amountController.dispose();
    _remarksController.dispose();
    super.dispose();
  }

  bool get _canSubmit =>
      !_submitting &&
      _selectedDealer != null &&
      _photoPath != null &&
      (_amountController.text.trim().isNotEmpty);

  Future<void> _openDealerSelector() async {
    final dealers = await _dealersFuture;
    if (!mounted) return;

    final searchController = TextEditingController();
    final selected = await showModalBottomSheet<OrderDealer>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            final query = searchController.text.trim().toLowerCase();
            final filtered = dealers.where((dealer) {
              if (query.isEmpty) return true;
              return dealer.name.toLowerCase().contains(query) ||
                  (dealer.ownerName ?? '').toLowerCase().contains(query) ||
                  (dealer.mobile ?? '').contains(query);
            }).toList();

            return Padding(
              padding: EdgeInsets.only(
                left: 16,
                right: 16,
                top: 8,
                bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      labelText: 'Search dealer',
                      prefixIcon: Icon(Icons.search),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 12),
                  Flexible(
                    child: ListView.separated(
                      shrinkWrap: true,
                      itemCount: filtered.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (context, index) {
                        final dealer = filtered[index];
                        return ListTile(
                          title: Text(dealer.name),
                          subtitle: Text(
                            [dealer.ownerName, dealer.mobile]
                                .where(
                                  (part) => part != null && part.isNotEmpty,
                                )
                                .join(' • '),
                          ),
                          onTap: () => Navigator.pop(context, dealer),
                        );
                      },
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    searchController.dispose();
    if (selected != null) {
      setState(() => _selectedDealer = selected);
    }
  }

  Future<void> _pickPhoto(ImageSource source) async {
    final image = await ImagePicker().pickImage(
      source: source,
      imageQuality: 78,
      maxWidth: 1440,
    );
    if (image == null) return;
    setState(() => _photoPath = image.path);
  }

  Future<void> _choosePhotoSource() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Camera'),
              onTap: () => Navigator.pop(context, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Gallery'),
              onTap: () => Navigator.pop(context, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );

    if (source != null) {
      await _pickPhoto(source);
    }
  }

  Future<void> _pickCollectionDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _collectionDate,
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
    );
    if (picked != null) {
      setState(() => _collectionDate = picked);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedDealer == null || _photoPath == null || _submitting) return;

    setState(() => _submitting = true);

    try {
      final api = CollectionApi(
        ApiClient(
          SessionStore(),
          onUnauthorized: widget.auth.sessionExpired,
        ).dio,
      );

      final message = await api.submit(
        dealerId: _selectedDealer!.id,
        amount: double.parse(_amountController.text.trim()),
        collectionDate: _collectionDate,
        photoPath: _photoPath!,
        remarks: _remarksController.text,
      );

      if (!mounted) return;

      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$error')));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final dateLabel = DateFormat('d MMM yyyy').format(_collectionDate);

    return PgPageScaffold(
      title: 'New Collection',
      showBack: true,
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            PgCard(
              onTap: _openDealerSelector,
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Select Dealer',
                          style: Theme.of(context).textTheme.labelLarge,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          _selectedDealer?.name ?? 'Tap to choose a dealer',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right_rounded),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: TextFormField(
                controller: _amountController,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Collection Amount',
                  prefixText: '₹ ',
                  border: InputBorder.none,
                ),
                validator: (value) {
                  final amount = double.tryParse(value?.trim() ?? '');
                  if (amount == null || amount <= 0) {
                    return 'Enter an amount greater than 0.';
                  }
                  return null;
                },
                onChanged: (_) => setState(() {}),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              onTap: _pickCollectionDate,
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Collection Date',
                          style: Theme.of(context).textTheme.labelLarge,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          dateLabel,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.calendar_today_outlined),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Photo Attachment',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (_photoPath == null)
                    OutlinedButton.icon(
                      onPressed: _choosePhotoSource,
                      icon: const Icon(Icons.add_a_photo_outlined),
                      label: const Text('Add Photo'),
                    )
                  else ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: Image.file(
                        File(_photoPath!),
                        height: 180,
                        width: double.infinity,
                        fit: BoxFit.cover,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Row(
                      children: [
                        TextButton(
                          onPressed: _choosePhotoSource,
                          child: const Text('Replace'),
                        ),
                        TextButton(
                          onPressed: () => setState(() => _photoPath = null),
                          child: const Text('Remove'),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: TextFormField(
                controller: _remarksController,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Remarks (optional)',
                  alignLabelWithHint: true,
                  border: InputBorder.none,
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: FilledButton(
                onPressed: _canSubmit ? _submit : null,
                child: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Submit Collection'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
